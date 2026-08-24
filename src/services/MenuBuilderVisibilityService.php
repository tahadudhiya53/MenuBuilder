<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use Tahadudhiya\MenuBuilder\events\RegisterVisibilityRulesEvent;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\visibility\AlwaysRule;
use Tahadudhiya\MenuBuilder\visibility\DateRangeRule;
use Tahadudhiya\MenuBuilder\visibility\EnvironmentRule;
use Tahadudhiya\MenuBuilder\visibility\LoggedInRule;
use Tahadudhiya\MenuBuilder\visibility\LoggedOutRule;
use Tahadudhiya\MenuBuilder\visibility\SiteRule;
use Tahadudhiya\MenuBuilder\visibility\UserGroupRule;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;
use Tahadudhiya\MenuBuilder\visibility\VisibilityRuleInterface;

class MenuBuilderVisibilityService extends Component
{
    public const EVENT_REGISTER_VISIBILITY_RULES = 'registerVisibilityRules';

    /** @var array<string,VisibilityRuleInterface>|null */
    private ?array $rules = null;

    public function buildContext(): VisibilityContext
    {
        $user = Craft::$app->getUser()->getIdentity();
        $timezone = new \DateTimeZone(Craft::$app->getTimeZone());

        return new VisibilityContext(
            isLoggedIn: $user !== null,
            userGroupIds: $user !== null ? array_map(fn($group) => (int)$group->id, $user->getGroups()) : [],
            currentSiteId: Craft::$app->getIsInstalled() ? Craft::$app->getSites()->getCurrentSite()->id : null,
            now: new \DateTime('now', $timezone),
            environment: Craft::$app->env,
            timezone: $timezone,
        );
    }

    public function isVisible(MenuBuilderItem $item, VisibilityContext $context): bool
    {
        if (empty($item->visibility)) {
            return true;
        }

        $rules = $this->getRules();

        foreach ($item->visibility as $ruleConfig) {
            $type = $ruleConfig['type'] ?? null;
            $rule = $type ? ($rules[$type] ?? null) : null;

            // An unknown/misconfigured rule type fails closed rather than silently
            // showing content that was meant to be gated.
            if ($rule === null || !$rule->passes($ruleConfig, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,VisibilityRuleInterface>
     */
    private function getRules(): array
    {
        if ($this->rules === null) {
            $event = new RegisterVisibilityRulesEvent([
                'rules' => [
                    'always' => new AlwaysRule(),
                    'loggedIn' => new LoggedInRule(),
                    'loggedOut' => new LoggedOutRule(),
                    'userGroup' => new UserGroupRule(),
                    'site' => new SiteRule(),
                    'dateRange' => new DateRangeRule(),
                    'environment' => new EnvironmentRule(),
                ],
            ]);
            $this->trigger(self::EVENT_REGISTER_VISIBILITY_RULES, $event);
            $this->rules = $event->rules;
        }

        return $this->rules;
    }
}

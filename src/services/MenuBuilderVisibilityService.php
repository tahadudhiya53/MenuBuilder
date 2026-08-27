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

    /**
     * An item's rules are combined with AND: visible only if every one of
     * them passes. Everything that isn't an unambiguous pass fails closed —
     * an entry that isn't even an array of config, a missing or non-string
     * `type`, a type no rule is registered for, or a rule that throws.
     * A malformed `visibility` bag must hide the item, never expose it.
     */
    public function isVisible(MenuBuilderItem $item, VisibilityContext $context): bool
    {
        if (empty($item->visibility)) {
            return true;
        }

        $rules = $this->getRules();

        foreach ($item->visibility as $ruleConfig) {
            // Guarded rather than assumed: `visibility` is persisted as free-form
            // JSON, so a directly-posted or imported bag can hold a scalar here,
            // and `$ruleConfig['type']` on a string is a TypeError in PHP 8 — an
            // uncaught one would surface as a 500 instead of a hidden item.
            if (!is_array($ruleConfig)) {
                return false;
            }

            $type = $ruleConfig['type'] ?? null;

            // Non-string type (an array or int) would be an illegal offset on
            // the rules map, so it's rejected before the lookup.
            if (!is_string($type) || $type === '') {
                return false;
            }

            $rule = $rules[$type] ?? null;

            if ($rule === null) {
                return false;
            }

            // A rule is allowed to be third-party (EVENT_REGISTER_VISIBILITY_RULES).
            // If one throws on config it didn't expect, that's still a rule that
            // did not pass — hide the item rather than letting the exception
            // escape and take out the whole page render.
            try {
                if (!$rule->passes($ruleConfig, $context)) {
                    return false;
                }
            } catch (\Throwable $e) {
                Craft::warning(
                    "MenuBuilder visibility rule \"{$type}\" threw while evaluating item {$item->id}; failing closed: " . $e->getMessage(),
                    __METHOD__
                );

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

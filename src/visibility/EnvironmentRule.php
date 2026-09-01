<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;

/**
 * Config: {"environments": ["production", "staging"]} — matches against
 * `Craft::$app->env` (CRAFT_ENVIRONMENT).
 *
 * Fails closed for malformed/empty config and for an unknown environment,
 * same reasoning as {@see SiteRule}: an environment rule with nothing to
 * match on is missing configuration, and a staging-only item must not leak
 * into an environment the plugin can't identify.
 */
class EnvironmentRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        $environments = ConfigHelper::strictStringList($config['environments'] ?? null);

        if (empty($environments) || $context->environment === null) {
            return false;
        }

        return in_array($context->environment, $environments, true);
    }
}

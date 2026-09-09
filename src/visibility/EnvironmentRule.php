<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;

/**
 * Config: {"environments": ["production", "staging"]} — matches against `Craft::$app->env`
 * (CRAFT_ENVIRONMENT).
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

<?php

namespace Tahadudhiya\MenuBuilder\visibility;

/** Config: {"environments": ["production", "staging"]} */
class EnvironmentRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        $environments = $config['environments'] ?? [];

        if (empty($environments)) {
            return true;
        }

        return $context->environment !== null && in_array($context->environment, $environments, true);
    }
}

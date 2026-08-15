<?php

namespace Tahadudhiya\MenuBuilder\visibility;

class AlwaysRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        return true;
    }
}

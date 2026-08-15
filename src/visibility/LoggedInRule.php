<?php

namespace Tahadudhiya\MenuBuilder\visibility;

class LoggedInRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        return $context->isLoggedIn;
    }
}

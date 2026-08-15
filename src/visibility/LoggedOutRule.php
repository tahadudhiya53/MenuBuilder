<?php

namespace Tahadudhiya\MenuBuilder\visibility;

class LoggedOutRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        return !$context->isLoggedIn;
    }
}

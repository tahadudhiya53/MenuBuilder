<?php

namespace Tahadudhiya\MenuBuilder\visibility;

/**
 * One evaluator per visibility rule `type`.
*/
interface VisibilityRuleInterface
{
    /** @param array<string,mixed> $config The rule's own config, e.g. */
    public function passes(array $config, VisibilityContext $context): bool;
}

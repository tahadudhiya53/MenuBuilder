<?php

namespace Tahadudhiya\MenuBuilder\visibility;

/**
 * One evaluator per visibility rule `type`. New rule types can be registered
 * on MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES without any
 * model or migration change — visibility is deliberately not coupled to the
 * database schema.
 */
interface VisibilityRuleInterface
{
    /**
     * @param array<string,mixed> $config The rule's own config, e.g. ['groupIds' => [1,2]].
     */
    public function passes(array $config, VisibilityContext $context): bool;
}

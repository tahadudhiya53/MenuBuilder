<?php

namespace Tahadudhiya\MenuBuilder\visibility;

/** Config: {"groupIds": [1, 2]} — passes if the current user belongs to any of them. */
class UserGroupRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        if (!$context->isLoggedIn) {
            return false;
        }

        $groupIds = array_map('intval', $config['groupIds'] ?? []);

        if (empty($groupIds)) {
            return true;
        }

        return !empty(array_intersect($groupIds, $context->userGroupIds));
    }
}

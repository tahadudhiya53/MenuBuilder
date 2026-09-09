<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;

/**
 * Config: {"groupIds": [1, 2]} — passes only if the current user belongs to one of them.
*/
class UserGroupRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        if (!$context->isLoggedIn) {
            return false;
        }

        $groupIds = ConfigHelper::strictIdList($config['groupIds'] ?? null);

        if (empty($groupIds)) {
            return false;
        }

        return !empty(array_intersect($groupIds, $context->userGroupIds));
    }
}

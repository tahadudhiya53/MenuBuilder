<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;

/**
 * Config: {"siteIds": [1, 2]} — passes only if the current site is one of them.
*/
class SiteRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        $siteIds = ConfigHelper::strictIdList($config['siteIds'] ?? null);

        if (empty($siteIds) || $context->currentSiteId === null) {
            return false;
        }

        return in_array($context->currentSiteId, $siteIds, true);
    }
}

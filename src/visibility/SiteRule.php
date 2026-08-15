<?php

namespace Tahadudhiya\MenuBuilder\visibility;

/** Config: {"siteIds": [1, 2]} — passes if the current site is one of them. */
class SiteRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        $siteIds = array_map('intval', $config['siteIds'] ?? []);

        if (empty($siteIds) || $context->currentSiteId === null) {
            return true;
        }

        return in_array($context->currentSiteId, $siteIds, true);
    }
}

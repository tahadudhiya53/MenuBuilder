<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;

/**
 * Config: {"siteIds": [1, 2]} — passes only if the current site is one of them.
 *
 * Fails closed in every other case, because this rule exists solely to
 * restrict: a malformed `siteIds` (not a list of positive IDs), an empty or
 * absent list, or a request with no resolvable current site all hide the
 * item rather than showing navigation that was meant to be site-gated.
 * "Show everywhere" is the absence of a site rule, not an empty one — the CP
 * editor never persists an empty list (ItemsController::buildVisibilityRules)
 * and MenuBuilderItem::validateVisibility() rejects one on save, so this
 * only ever fires for a directly-posted or imported config.
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

<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

class UrlLinkResolver implements LinkTypeResolverInterface
{
    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        if (!$item->customUrl) {
            return ResolvedLink::unavailable();
        }

        // MenuBuilderItem::validateCustomUrl() already rejects this on save,
        // so this is defense-in-depth for a value that never went through
        // that path — an imported/migrated row, a direct DB edit, or a row
        // written before the scheme denylist existed. The resolved URL is
        // rendered straight into an `href`, where Twig's escaping stops
        // injection but not a scheme that executes instead of navigating,
        // so an unsafe stored value resolves to "unavailable" rather than
        // being emitted.
        if (!MenuBuilderItem::isPermissiveUrl($item->customUrl)) {
            return ResolvedLink::unavailable();
        }

        return ResolvedLink::to($item->customUrl);
    }
}

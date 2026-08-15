<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

class AnchorLinkResolver implements LinkTypeResolverInterface
{
    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        $handle = $item->handle ?: $item->customUrl;

        if (!$handle) {
            return ResolvedLink::unavailable();
        }

        return ResolvedLink::to('#' . ltrim($handle, '#'));
    }
}

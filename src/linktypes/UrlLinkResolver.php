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

        return ResolvedLink::to($item->customUrl);
    }
}

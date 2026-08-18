<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

/**
 * Shared by nonclickable and separator types — a structural heading/group
 * label that never resolves to a link. There is no alternate "give it a link
 * anyway" path: isLinkable() on the item is authoritative, and this resolver
 * matches that by always returning no link, regardless of any leftover
 * customUrl/clickable value on the item.
 */
class NonClickableLinkResolver implements LinkTypeResolverInterface
{
    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        return ResolvedLink::none();
    }
}

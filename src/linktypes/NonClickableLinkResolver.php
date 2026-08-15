<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

/**
 * Shared by nonclickable and separator types — a heading/group label with no
 * href at all, unless the editor explicitly gave it a custom URL (spec §5:
 * a non-clickable parent can optionally still have its own URL).
 */
class NonClickableLinkResolver implements LinkTypeResolverInterface
{
    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        if (!$item->clickable) {
            return ResolvedLink::none();
        }

        if ($item->customUrl) {
            return ResolvedLink::to($item->customUrl);
        }

        return ResolvedLink::none();
    }
}

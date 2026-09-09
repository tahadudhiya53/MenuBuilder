<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

class AnchorLinkResolver implements LinkTypeResolverInterface
{
    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        $target = self::anchorTarget($item);

        // Fragments reach the rendered href just like a customUrl does, and a stored value isn't
        // guaranteed to have gone through the CP's validation (import, direct DB edit, a
        // pre-existing row).
        if ($target === null || !MenuBuilderItem::isValidAnchorTarget($target)) {
            return ResolvedLink::unavailable();
        }

        return ResolvedLink::to('#' . ltrim($target, '#'));
    }

    /**
     * The editor's "Anchor handle" field posts `customUrl` and is documented as "leave blank to
     * reuse the Handle field in Advanced" (see items/_fields.twig), so customUrl wins and `handle`
     * — which doubles as the CSS-targeting handle — is only the fallback.
    */
    public static function anchorTarget(MenuBuilderItem $item): ?string
    {
        foreach ([$item->customUrl, $item->handle] as $candidate) {
            $candidate = trim((string)$candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}

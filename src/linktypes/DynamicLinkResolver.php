<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

/**
 * A `dynamic` item is a container for the children MenuBuilderResolver
 * synthesizes from its `metadata['dynamicSource']` config — it has no link
 * destination of its own, so it resolves to "available, but no URL" exactly
 * like a heading does.
 *
 * Registering this matters beyond tidiness: with no resolver for the type,
 * MenuBuilderLinkResolver fell through to ResolvedLink::unavailable(), and
 * MenuBuilderResolver::convert() drops any unavailable item whose
 * fallbackBehavior is FALLBACK_HIDE — the default, and the only value a
 * dynamic item can ever have, since the editor's fallback fields are
 * type-scoped to the link types that can actually resolve to an element
 * (see items/_fields.twig). Every dynamic item, and every child it would
 * have generated, disappeared from the rendered tree.
 */
class DynamicLinkResolver implements LinkTypeResolverInterface
{
    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        return ResolvedLink::none();
    }
}

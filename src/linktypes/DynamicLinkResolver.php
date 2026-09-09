<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

/**
 * A `dynamic` item is a container for the children MenuBuilderResolver synthesizes from its
 * `metadata['dynamicSource']` config — it has no link destination of its own, so it resolves to
 * "available, but no URL" exactly like a heading does.
*/
class DynamicLinkResolver implements LinkTypeResolverInterface
{
    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        return ResolvedLink::none();
    }
}

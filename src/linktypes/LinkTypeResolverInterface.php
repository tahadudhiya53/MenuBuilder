<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

/**
 * One resolver per MenuBuilderItem::TYPE_* value. New link types can be added
 * by registering an implementation on
 * MenuBuilderLinkResolver::EVENT_REGISTER_LINK_TYPES without touching core.
 */
interface LinkTypeResolverInterface
{
    public function resolve(MenuBuilderItem $item): ResolvedLink;
}

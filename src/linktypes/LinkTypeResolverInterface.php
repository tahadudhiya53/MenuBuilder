<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

/**
 * One resolver per MenuBuilderItem::TYPE_* value.
*/
interface LinkTypeResolverInterface
{
    public function resolve(MenuBuilderItem $item): ResolvedLink;
}

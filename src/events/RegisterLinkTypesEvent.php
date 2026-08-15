<?php

namespace Tahadudhiya\MenuBuilder\events;

use yii\base\Event;

/**
 * @property array<string,\Tahadudhiya\MenuBuilder\linktypes\LinkTypeResolverInterface> $resolvers Keyed by MenuBuilderItem::TYPE_* value.
 */
class RegisterLinkTypesEvent extends Event
{
    /** @var array<string,\Tahadudhiya\MenuBuilder\linktypes\LinkTypeResolverInterface> */
    public array $resolvers = [];
}

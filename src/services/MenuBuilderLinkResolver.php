<?php

namespace Tahadudhiya\MenuBuilder\services;

use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use Tahadudhiya\MenuBuilder\events\RegisterLinkTypesEvent;
use Tahadudhiya\MenuBuilder\linktypes\AnchorLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\ElementLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\LinkTypeResolverInterface;
use Tahadudhiya\MenuBuilder\linktypes\NonClickableLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\UrlLinkResolver;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

class MenuBuilderLinkResolver extends Component
{
    public const EVENT_REGISTER_LINK_TYPES = 'registerLinkTypes';

    /** @var array<string,LinkTypeResolverInterface>|null */
    private ?array $resolvers = null;

    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        $resolver = $this->getResolvers()[$item->type] ?? null;

        return $resolver ? $resolver->resolve($item) : ResolvedLink::unavailable();
    }

    /**
     * @return array<string,LinkTypeResolverInterface>
     */
    private function getResolvers(): array
    {
        if ($this->resolvers === null) {
            $event = new RegisterLinkTypesEvent([
                'resolvers' => [
                    MenuBuilderItem::TYPE_ENTRY => new ElementLinkResolver(Entry::class),
                    MenuBuilderItem::TYPE_CATEGORY => new ElementLinkResolver(Category::class),
                    MenuBuilderItem::TYPE_ASSET => new ElementLinkResolver(Asset::class),
                    MenuBuilderItem::TYPE_URL => new UrlLinkResolver(),
                    MenuBuilderItem::TYPE_ANCHOR => new AnchorLinkResolver(),
                    MenuBuilderItem::TYPE_NONCLICKABLE => new NonClickableLinkResolver(),
                    MenuBuilderItem::TYPE_SEPARATOR => new NonClickableLinkResolver(),
                ],
            ]);
            $this->trigger(self::EVENT_REGISTER_LINK_TYPES, $event);
            $this->resolvers = $event->resolvers;
        }

        return $this->resolvers;
    }
}

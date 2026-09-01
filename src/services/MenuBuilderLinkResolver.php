<?php

namespace Tahadudhiya\MenuBuilder\services;

use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use Tahadudhiya\MenuBuilder\events\RegisterLinkTypesEvent;
use Tahadudhiya\MenuBuilder\linktypes\AnchorLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\DynamicLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\ElementLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\LinkTypeResolverInterface;
use Tahadudhiya\MenuBuilder\linktypes\NonClickableLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\PreloadingLinkTypeResolverInterface;
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
     * Tells every preload-capable link type which elements the tree about to
     * be built will ask for, so an entry/category/asset link costs a shared
     * query rather than one apiece
     * ({@see PreloadingLinkTypeResolverInterface}).
     *
     * Grouped by item *type* rather than pooled, because each type resolves
     * against its own element class — an entry ID handed to the category
     * resolver simply isn't there. Types whose resolver doesn't preload are
     * skipped, which is what keeps a third-party resolver working unchanged.
     *
     * @param MenuBuilderItem[] $items Top-level items; children are walked.
     */
    public function preload(array $items): void
    {
        $idsByType = [];
        $this->collectElementIds($items, $idsByType);

        foreach ($idsByType as $type => $elementIds) {
            $resolver = $this->getResolvers()[$type] ?? null;

            if ($resolver instanceof PreloadingLinkTypeResolverInterface) {
                $resolver->preload($elementIds);
            }
        }
    }

    /**
     * Drops every preloaded element. Called once the tree is built: the
     * resolvers live for the whole request, the elements are wanted only for
     * the build.
     */
    public function releasePreloaded(): void
    {
        foreach ($this->getResolvers() as $resolver) {
            if ($resolver instanceof PreloadingLinkTypeResolverInterface) {
                $resolver->releasePreloaded();
            }
        }
    }

    /**
     * @param MenuBuilderItem[] $items
     * @param array<string,int[]> $idsByType
     */
    private function collectElementIds(array $items, array &$idsByType): void
    {
        foreach ($items as $item) {
            if ($item->elementId !== null) {
                $idsByType[$item->type][] = (int)$item->elementId;
            }

            $this->collectElementIds($item->children, $idsByType);
        }
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
                    MenuBuilderItem::TYPE_DYNAMIC => new DynamicLinkResolver(),
                ],
            ]);
            $this->trigger(self::EVENT_REGISTER_LINK_TYPES, $event);
            $this->resolvers = $event->resolvers;
        }

        return $this->resolvers;
    }
}

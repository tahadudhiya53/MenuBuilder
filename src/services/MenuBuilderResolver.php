<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

/**
 * The single entry point Twig talks to (via MenuBuilderVariable). Pipeline:
 * load raw tree -> resolve links (cached) -> filter visibility (fresh,
 * per-request) -> mark active state (fresh) -> return a MenuBuilderTree.
 */
class MenuBuilderResolver extends Component
{
    public function getTree(string $groupHandle, ?string $currentUri = null): ?MenuBuilderTree
    {
        $group = MenuBuilder::getInstance()->groups->getByHandle($groupHandle);

        if ($group === null || !$group->enabled) {
            return null;
        }

        $resolvedNodes = MenuBuilder::getInstance()->cache->getOrSet(
            $groupHandle,
            fn() => $this->buildResolvedNodes($group->id)
        );

        $visibilityService = MenuBuilder::getInstance()->visibility;
        $context = $visibilityService->buildContext();

        $itemsById = [];
        foreach (MenuBuilder::getInstance()->items->getFlatForGroup($group->id, includeDisabled: false) as $item) {
            $itemsById[$item->id] = $item;
        }

        $filtered = $this->filterVisible($resolvedNodes, $itemsById, $visibilityService, $context);

        $currentUri ??= Craft::$app->getRequest()->getIsConsoleRequest()
            ? '/'
            : Craft::$app->getRequest()->getFullUri();

        MenuBuilder::getInstance()->activeResolver->mark($filtered, $currentUri);

        return new MenuBuilderTree($group, $filtered);
    }

    /**
     * Builds the link-resolved node tree for caching. Visibility is
     * intentionally NOT applied here — it depends on the current user/date
     * and must never be baked into a shared cache entry.
     *
     * @return MenuBuilderNode[]
     */
    private function buildResolvedNodes(int $groupId): array
    {
        $items = MenuBuilder::getInstance()->items->getTree($groupId, includeDisabled: false);

        return $this->convert($items, 1, null);
    }

    /**
     * @param MenuBuilderItem[] $items
     * @return MenuBuilderNode[]
     */
    private function convert(array $items, int $level, ?MenuBuilderNode $parent): array
    {
        $nodes = [];

        foreach ($items as $item) {
            $resolvedLink = MenuBuilder::getInstance()->linkResolver->resolve($item);

            if (!$resolvedLink->isAvailable && $item->fallbackBehavior === MenuBuilderItem::FALLBACK_HIDE) {
                continue;
            }

            $isClickable = $item->isLinkable() && $item->clickable && $resolvedLink->url !== null;

            $node = new MenuBuilderNode(
                id: $item->id,
                handle: $item->handle,
                type: $item->type,
                title: $item->title,
                url: $resolvedLink->url,
                isClickable: $isClickable,
                isLinkAvailable: $resolvedLink->isAvailable,
                target: $item->target,
                rel: $item->rel,
                cssClass: $item->cssClass,
                htmlId: $item->htmlId,
                htmlAttributes: $item->htmlAttributes,
                ariaLabel: $item->ariaLabel,
                titleAttribute: $item->titleAttribute,
                icon: $item->icon,
                badge: $item->badge,
                description: $item->description,
                image: $item->image,
                featured: $item->featured,
                level: $level,
            );

            $node->parent = $parent;
            $node->children = $this->convert($item->children, $level + 1, $node);
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Visibility rules live on the persisted MenuBuilderItem, not the cached
     * MenuBuilderNode, so re-check against the current raw items — passed in
     * by getTree() rather than re-queried per node.
     *
     * @param MenuBuilderNode[] $nodes
     * @param array<int,MenuBuilderItem> $itemsById
     * @return MenuBuilderNode[]
     */
    private function filterVisible(array $nodes, array $itemsById, MenuBuilderVisibilityService $visibilityService, VisibilityContext $context): array
    {
        $filtered = [];

        foreach ($nodes as $node) {
            $item = $itemsById[$node->id] ?? null;

            if ($item !== null && !$visibilityService->isVisible($item, $context)) {
                continue;
            }

            $node->children = $this->filterVisible($node->children, $itemsById, $visibilityService, $context);
            $filtered[] = $node;
        }

        return $filtered;
    }
}

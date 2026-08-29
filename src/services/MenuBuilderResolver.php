<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use Tahadudhiya\MenuBuilder\helpers\BadgeHelper;
use Tahadudhiya\MenuBuilder\helpers\CustomFieldHelper;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderCustomField;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

/**
 * The single entry point Twig talks to (via MenuBuilderVariable). Pipeline:
 * load raw tree -> resolve links (cached) -> filter visibility (fresh,
 * per-request) -> mark active state (fresh) -> return a MenuBuilderTree.
 */
class MenuBuilderResolver extends Component
{
    /**
     * @param VisibilityContext|null $context The audience to resolve for. Defaults to the
     *                                        current request's — the only caller that passes
     *                                        one is MenuBuilderPreviewService, which
     *                                        substitutes a *simulated* audience.
     *
     *                                        It is taken here, after the cached step, rather
     *                                        than plumbed into it, because that is the same
     *                                        boundary the real context observes: visibility is
     *                                        filtered per request and never written into a
     *                                        shared cache entry, so a preview reads exactly the
     *                                        entry a visitor reads and cannot leave its
     *                                        simulated audience behind in one (see
     *                                        ARCHITECTURE.md "Caching" and "Preview").
     */
    public function getTree(string $groupHandle, ?string $currentUri = null, ?VisibilityContext $context = null): ?MenuBuilderTree
    {
        $group = MenuBuilder::getInstance()->groups->getByHandle($groupHandle);

        if ($group === null || !$group->enabled) {
            return null;
        }

        $visibilityService = MenuBuilder::getInstance()->visibility;
        $context ??= $visibilityService->buildContext();

        // Group-level site restriction (MenuBuilderGroup::$siteIds) gates the
        // whole menu before any items are loaded — the coarse counterpart to
        // the per-item `site` visibility rule.
        if (!$group->isAvailableForSite($context->currentSiteId)) {
            return null;
        }

        $resolvedNodes = MenuBuilder::getInstance()->cache->getOrSet(
            $group,
            fn() => $this->buildResolvedNodes($group->id, $group->customFields)
        );

        $itemsById = [];
        foreach (MenuBuilder::getInstance()->items->getFlatForGroup($group->id, includeDisabled: false) as $item) {
            $itemsById[$item->id] = $item;
        }

        $filtered = $this->filterVisible($resolvedNodes, $itemsById, $visibilityService, $context);

        $request = Craft::$app->getRequest();
        $isConsoleRequest = $request->getIsConsoleRequest();

        $currentUri ??= $isConsoleRequest ? '/' : $request->getFullUri();

        MenuBuilder::getInstance()->activeResolver->mark(
            $filtered,
            $currentUri,
            $isConsoleRequest ? [] : self::internalHosts($request->getHostName(), $this->currentSiteBaseUrl())
        );

        return new MenuBuilderTree($group, $filtered);
    }

    /**
     * The hosts MenuBuilderActiveResolver treats as "the site being served"
     * when deciding whether an absolute item URL can be the current page: the
     * host actually being requested, plus the *current* site's own base-URL
     * host.
     *
     * The base URL matters because an element link is built from it, and it
     * isn't always spelled the same way as the request (`www.` vs bare, a
     * base URL behind a proxy). Without it a legitimately internal absolute
     * URL would stop matching; with only the request host it would be
     * indistinguishable from a link to somebody else's site.
     *
     * Deliberately *not* every site's base URL. Sibling sites of the same
     * install routinely share a path structure — `/contact` exists on
     * English, German and French — and a URL on another site's domain is by
     * definition not the page currently being served, so admitting those
     * hosts marked the German link as the current page while English was
     * being rendered (`aria-current="page"` on the wrong link, and the wrong
     * branch styled open). A cross-site link still resolves active state
     * normally on the site it points at: the request host is that site's
     * host then, and it's always in this list.
     *
     * Pure + static (the base URL is gathered by
     * {@see currentSiteBaseUrl()} and passed in) so the multi-site half of
     * active state is unit-testable without a booted Craft app, the same
     * reasoning as ElementLinkResolver::isPubliclyAvailable().
     *
     * @return string[]
     */
    public static function internalHosts(?string $requestHost, ?string $currentSiteBaseUrl): array
    {
        return array_values(array_filter([$requestHost, $currentSiteBaseUrl], fn(?string $host) => $host !== null));
    }

    /**
     * The base URL of the site being rendered, or null when it has none (a
     * site with no base URL can't produce an absolute element URL to match
     * against in the first place).
     */
    private function currentSiteBaseUrl(): ?string
    {
        return Craft::$app->getSites()->getCurrentSite()->getBaseUrl();
    }

    /**
     * Builds the link-resolved node tree for caching. Visibility is
     * intentionally NOT applied here — it depends on the current user/date
     * and must never be baked into a shared cache entry.
     *
     * Custom field *values*, by contrast, belong in the cached payload:
     * they are a function of the item and the menu's definitions, and of
     * nothing about the visitor or the request. The definitions are read
     * once here and passed down rather than looked up per item — they
     * belong to the menu, so one read covers the whole tree.
     *
     * @param MenuBuilderCustomField[] $customFields
     * @return MenuBuilderNode[]
     */
    private function buildResolvedNodes(int $groupId, array $customFields = []): array
    {
        $items = MenuBuilder::getInstance()->items->getTree($groupId, includeDisabled: false);

        return $this->convert($items, 1, null, $customFields);
    }

    /**
     * @param MenuBuilderItem[] $items
     * @param MenuBuilderCustomField[] $customFields
     * @return MenuBuilderNode[]
     */
    private function convert(array $items, int $level, ?MenuBuilderNode $parent, array $customFields = []): array
    {
        $nodes = [];

        foreach ($items as $item) {
            $resolvedLink = MenuBuilder::getInstance()->linkResolver->resolve($item);

            if (!$resolvedLink->isAvailable && $item->fallbackBehavior === MenuBuilderItem::FALLBACK_HIDE) {
                continue;
            }

            $isClickable = LinkAttributeHelper::isClickable($item->isLinkable(), $item->clickable, $resolvedLink->url);
            $megaMenuConfig = $this->buildMegaMenuConfig($item);

            $node = new MenuBuilderNode(
                id: $item->id,
                handle: $item->handle,
                type: $item->type,
                title: LinkAttributeHelper::resolveTitle($item->title, $resolvedLink->label),
                url: $resolvedLink->url,
                isClickable: $isClickable,
                isLinkAvailable: $resolvedLink->isAvailable,
                target: $item->target,
                rel: LinkAttributeHelper::mergeRelForTarget($item->target, $item->rel),
                cssClass: $item->cssClass,
                htmlId: $item->htmlId,
                htmlAttributes: $item->htmlAttributes,
                ariaLabel: $item->ariaLabel,
                titleAttribute: $item->titleAttribute,
                icon: $item->icon,
                // Fail-closed reads, not raw column values: a badge written
                // straight into the database normalizes here, and an unknown
                // style becomes "no style" rather than a class list.
                badge: BadgeHelper::text($item->badge),
                description: $item->description,
                image: $item->image,
                featured: $item->featured,
                level: $level,
                megaMenu: $megaMenuConfig,
                megaMenuColumn: $this->intOrNull($item->metadata['megaMenuColumn'] ?? null),
                badgeStyle: BadgeHelper::style($item->metadata['badgeStyle'] ?? null),
                // Fail-closed against the menu's *current* definitions: a
                // field since deleted or retyped, or a value written
                // straight into the database, is dropped here rather than
                // reaching a template.
                customFields: CustomFieldHelper::valuesForOutput($customFields, $item->metadata[CustomFieldHelper::VALUES_KEY] ?? null),
            );

            $node->parent = $parent;
            $node->children = $this->convert($item->children, $level + 1, $node, $customFields);

            if ($item->type === MenuBuilderItem::TYPE_DYNAMIC && $item->enabled) {
                $node->children = array_merge($node->children, $this->buildDynamicChildren($item, $level + 1, $node));
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    private function buildMegaMenuConfig(MenuBuilderItem $item): ?MenuBuilderMegaMenuConfig
    {
        $config = $item->metadata['megaMenu'] ?? null;

        if (!is_array($config) || empty($config['enabled'])) {
            return null;
        }

        $columns = $config['columns'] ?? 1;

        return new MenuBuilderMegaMenuConfig(columns: is_int($columns) && $columns >= 1 && $columns <= 6 ? $columns : 1);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * Synthesizes MenuBuilderNode[] from a `dynamic` item's configured
     * source (MenuBuilderDynamicNavigationService) — never persisted as
     * MenuBuilderItem rows. These carry no visibility config of their own
     * (there's nothing to configure per-element); they're already
     * site/status-scoped by the query itself, same boundary a real
     * entry/category/asset link would respect.
     *
     * @return MenuBuilderNode[]
     */
    private function buildDynamicChildren(MenuBuilderItem $item, int $level, MenuBuilderNode $parent): array
    {
        $config = is_array($item->metadata['dynamicSource'] ?? null) ? $item->metadata['dynamicSource'] : [];
        $elements = MenuBuilder::getInstance()->dynamicNavigation->resolveElements($config);
        $nodes = [];

        foreach ($elements as $element) {
            $node = $this->buildDynamicNode($element, $level);
            $node->parent = $parent;
            $nodes[] = $node;
        }

        return $nodes;
    }

    private function buildDynamicNode(ElementInterface $element, int $level): MenuBuilderNode
    {
        $url = method_exists($element, 'getUrl') ? $element->getUrl() : null;
        $title = (string)($element->title ?? '');
        // A synthesized child is always "linkable" and always clickable when
        // it has a URL — there is no editor-set `clickable` flag to respect —
        // but it goes through the same helper so a blank URL is treated the
        // same way here as on a persisted item.
        $isClickable = LinkAttributeHelper::isClickable(true, true, $url);

        return new MenuBuilderNode(
            id: (int)$element->id,
            handle: null,
            type: MenuBuilderItem::TYPE_DYNAMIC,
            title: $title,
            url: $url,
            isClickable: $isClickable,
            isLinkAvailable: true,
            target: '_self',
            rel: null,
            cssClass: null,
            htmlId: null,
            htmlAttributes: [],
            ariaLabel: null,
            titleAttribute: null,
            icon: null,
            badge: null,
            description: null,
            image: null,
            featured: false,
            level: $level,
            isDynamic: true,
        );
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
            // A dynamic-navigation child's `id` is a Craft element ID, not a
            // MenuBuilderItem ID — looking it up in $itemsById could
            // collide with an unrelated real item that happens to share the
            // same numeric ID, and apply that item's visibility rules to
            // the wrong node. Synthetic nodes carry no visibility config of
            // their own (see MenuBuilderResolver::buildDynamicChildren()),
            // so they're always visible here — they're already
            // site/status-scoped by the query that produced them.
            if ($node->isDynamic) {
                $item = null;
            } else {
                $item = $itemsById[$node->id] ?? null;

                // A cached node whose persisted item is gone from the fresh
                // read — deleted, or disabled since the tree was cached —
                // is hidden rather than passed through unchecked: its
                // visibility rules are exactly what can't be evaluated any
                // more. Invalidation should already have prevented this
                // (MenuBuilderCacheService), so this is the fail-closed
                // backstop for a stale entry that outlived its item.
                if ($item === null) {
                    continue;
                }
            }

            if ($item !== null && !$visibilityService->isVisible($item, $context)) {
                continue;
            }

            // withChildren() rather than `$node->children = ...`: these
            // nodes came from the cache, and the filtered result is then
            // active-state marked, so writing either back onto them would
            // put per-request state on shared objects. See
            // MenuBuilderNode::withChildren().
            $filtered[] = $node->withChildren(
                $this->filterVisible($node->children, $itemsById, $visibilityService, $context)
            );
        }

        return $filtered;
    }
}

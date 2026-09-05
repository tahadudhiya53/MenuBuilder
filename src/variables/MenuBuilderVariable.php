<?php

namespace Tahadudhiya\MenuBuilder\variables;

use craft\elements\Asset;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderBreadcrumbTrail;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * Exposed in Twig as `craft.menuBuilder`. `get()` is the primary/documented
 * entry point (link-resolved, visibility-filtered, active-state-marked
 * tree); the rest are thin read-only passthroughs to the underlying
 * services for the less common cases where a template needs the group/item
 * itself rather than a rendered tree — see ARCHITECTURE.md
 * "Public Twig API".
 *
 * {% set menu = craft.menuBuilder.get('main') %}
 * {% for item in menu %}...{% endfor %}
 */
class MenuBuilderVariable
{
    public function get(string $groupHandle, ?string $currentUri = null): ?MenuBuilderTree
    {
        return MenuBuilder::getInstance()->resolver->getTree($groupHandle, $currentUri);
    }

    /**
     * The breadcrumb trail for the page being served, derived from the
     * menu's own hierarchy — never from the request URL's segments (see
     * {@see MenuBuilderBreadcrumbService}).
     *
     *     {% set trail = craft.menuBuilder.breadcrumbs('main') %}
     *
     * `null` when the menu doesn't exist, is disabled, or isn't available on
     * this site — the same three outcomes as {@see get()}. An **empty** trail
     * (`trail.isEmpty()`) when the menu is there but nothing in it is this
     * page: render no breadcrumbs.
     *
     * Pass a `MenuBuilderTree` instead of a handle to reuse a menu this
     * template has already resolved, so the page resolves it once:
     *
     *     {% set menu  = craft.menuBuilder.get('main') %}
     *     {% set trail = craft.menuBuilder.breadcrumbs(menu) %}
     *
     * @param MenuBuilderTree|string $menu The menu's handle, or an already-resolved tree.
     * @param string|null $currentUri Overrides the page the trail is built for, exactly as
     *                                {@see get()} does. Ignored when a resolved tree is passed —
     *                                that tree's active state was already decided.
     */
    public function breadcrumbs(MenuBuilderTree|string $menu, ?string $currentUri = null): ?MenuBuilderBreadcrumbTrail
    {
        $breadcrumbs = MenuBuilder::getInstance()->breadcrumbs;

        return $menu instanceof MenuBuilderTree
            ? $breadcrumbs->trailForTree($menu)
            : $breadcrumbs->getTrail($menu, $currentUri);
    }

    /** The group itself (name, handle, maxDepth, cssClass, htmlAttributes, settings) — not its resolved tree. */
    public function getGroup(string $handle): ?MenuBuilderGroup
    {
        return MenuBuilder::getInstance()->groups->getByHandle($handle);
    }

    /**
     * The Asset behind an `asset:` icon, or null for a class icon, no icon,
     * or an asset that has since been deleted.
     *
     * The resolved tree caches the *reference* (`asset:123`), never the
     * URL — an asset can be re-uploaded or moved without any menu item
     * changing, so a cached URL would go stale with nothing to invalidate
     * it. That leaves one query per icon at render time, so results are
     * memoized per request: a menu where twenty items share one icon costs
     * one query, not twenty.
     *
     *
     * @var array<int,Asset|null>
     */
    private array $iconAssets = [];

    public function iconAsset(MenuBuilderNode $node): ?Asset
    {
        $assetId = $node->iconAssetId();

        if ($assetId === null) {
            return null;
        }

        if (!array_key_exists($assetId, $this->iconAssets)) {
            $this->iconAssets[$assetId] = Asset::find()->id($assetId)->one();
        }

        return $this->iconAssets[$assetId];
    }

    /**
     * `customAsset()` is gone. A menu's custom fields are Craft fields now,
     * so an Assets field on a node returns Craft's own element query and is
     * read the way an asset field is read anywhere else in Craft:
     *
     *     {% set image = node.custom('promoImage').one() %}
     *
     * That query is Craft's to batch and cache; a lookup helper here would
     * only be a second, worse element cache in front of it. `iconAsset()`
     * stays, because an icon really is a bare ID on the node.
     */

    /** A single raw (unresolved, unfiltered) item by ID — mainly useful for admin/debug templates. */
    public function getItem(int $id): ?MenuBuilderItem
    {
        return MenuBuilder::getInstance()->items->getById($id);
    }
}

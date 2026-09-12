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
 * Exposed in Twig as `craft.menuBuilder`.
*/
class MenuBuilderVariable
{
    public function get(string $groupHandle, ?string $currentUri = null): ?MenuBuilderTree
    {
        return MenuBuilder::getInstance()->resolver->getTree($groupHandle, $currentUri);
    }

    /**
     * The breadcrumb trail for the page being served, derived from the menu's own hierarchy —
     * never from the request URL's segments (see {@see MenuBuilderBreadcrumbService}).
     *
     * @param MenuBuilderTree|string $menu The menu's handle, or an already-resolved tree.
     * @param string|null $currentUri Overrides the page the trail is built for, exactly as {@see get()} does.
    */
    public function breadcrumbs(MenuBuilderTree|string $menu, ?string $currentUri = null): ?MenuBuilderBreadcrumbTrail
    {
        $breadcrumbs = MenuBuilder::getInstance()->breadcrumbs;

        return $menu instanceof MenuBuilderTree
            ? $breadcrumbs->trailForTree($menu)
            : $breadcrumbs->getTrail($menu, $currentUri);
    }

    /**
     * The group itself (name, handle, maxDepth, cssClass, htmlAttributes, settings) — not its
     * resolved tree.
    */
    public function getGroup(string $handle): ?MenuBuilderGroup
    {
        return MenuBuilder::getInstance()->groups->getByHandle($handle);
    }

    /**
     * The Asset behind an `asset:` icon, or null for a class icon, no icon, or an asset that has
     * since been deleted.
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
     * A single raw (unresolved, unfiltered) item by ID — mainly useful for admin/debug templates.
    */
    public function getItem(int $id): ?MenuBuilderItem
    {
        return MenuBuilder::getInstance()->items->getById($id);
    }
}

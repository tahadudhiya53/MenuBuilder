<?php

namespace Tahadudhiya\MenuBuilder\variables;

use craft\elements\Asset;
use Tahadudhiya\MenuBuilder\MenuBuilder;
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
     * Shared with {@see customAsset()} — both look an Asset up by ID, so
     * one item's icon and another's `asset` custom field pointing at the
     * same file is still a single query.
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
     * The Asset behind an `asset` custom field, or null when the field is
     * empty, isn't an asset field, or points at an asset that has since
     * been deleted.
     *
     * Same contract and the same reasoning as {@see iconAsset()}: the
     * cached tree carries the asset **ID**, never a URL, and the lookup is
     * memoized per request so a menu where twenty items point at one image
     * costs one query.
     */
    public function customAsset(MenuBuilderNode $node, string $handle): ?Asset
    {
        $value = $node->custom($handle);

        if (!is_int($value) || $value < 1) {
            return null;
        }

        if (!array_key_exists($value, $this->iconAssets)) {
            $this->iconAssets[$value] = Asset::find()->id($value)->one();
        }

        return $this->iconAssets[$value];
    }

    /** A single raw (unresolved, unfiltered) item by ID — mainly useful for admin/debug templates. */
    public function getItem(int $id): ?MenuBuilderItem
    {
        return MenuBuilder::getInstance()->items->getById($id);
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\variables;

use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
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

    /** A single raw (unresolved, unfiltered) item by ID — mainly useful for admin/debug templates. */
    public function getItem(int $id): ?MenuBuilderItem
    {
        return MenuBuilder::getInstance()->items->getById($id);
    }
}

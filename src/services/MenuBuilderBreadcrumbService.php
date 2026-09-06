<?php

namespace Tahadudhiya\MenuBuilder\services;

use craft\base\Component;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderBreadcrumbTrail;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * Derives a breadcrumb trail from a menu, as pipeline step 6 — after link resolution, visibility
 * filtering and active-state marking have already happened (see MenuBuilderResolver::getTree()).
*/
class MenuBuilderBreadcrumbService extends Component
{
    /**
     * The trail for a menu by handle, or null when the menu doesn't exist, is disabled, or isn't
     * available on the current site — the same three outcomes, for the same reasons, as
     * MenuBuilderResolver::getTree(), so `breadcrumbs()` and `get()` never disagree about whether a
     * menu is there.
     *
     * @param string|null $currentUri
    */
    public function getTrail(string $groupHandle, ?string $currentUri = null): ?MenuBuilderBreadcrumbTrail
    {
        $tree = MenuBuilder::getInstance()->resolver->getTree($groupHandle, $currentUri);

        return $tree === null ? null : $this->trailForTree($tree);
    }

    /**
     * The trail for an already-resolved tree — what a template that has already called
     * `craft.menuBuilder.get()` should use, so one page render resolves the menu once.
    */
    public function trailForTree(MenuBuilderTree $tree): MenuBuilderBreadcrumbTrail
    {
        return new MenuBuilderBreadcrumbTrail($tree->group, $this->pathToActive($tree->items, []));
    }

    /**
     * Depth-first search for the active node, carrying the path taken to reach it — so the
     * ancestors come from this tree's own nesting rather than from `MenuBuilderNode::$parent`,
     * which a caller assembling nodes by hand could have left unwired, and which cannot be walked
     * without trusting it to be acyclic.
     *
     * @param MenuBuilderNode[] $nodes
     * @param MenuBuilderNode[] $ancestors
     * @return MenuBuilderNode[] Root first, active node last; empty when nothing below is active.
    */
    private function pathToActive(array $nodes, array $ancestors): array
    {
        foreach ($nodes as $node) {
            $path = [...$ancestors, $node];

            if ($node->isActive) {
                return $path;
            }

            // Descends unconditionally rather than only into branches flagged `isActiveAncestor`.
            $found = $this->pathToActive($node->children, $path);

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }
}

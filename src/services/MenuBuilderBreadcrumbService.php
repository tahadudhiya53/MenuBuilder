<?php

namespace Tahadudhiya\MenuBuilder\services;

use craft\base\Component;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderBreadcrumbTrail;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * Derives a breadcrumb trail from a menu, as pipeline step 6 — after link
 * resolution, visibility filtering and active-state marking have already
 * happened (see MenuBuilderResolver::getTree()).
 *
 * **The trail is the menu's own hierarchy, never a parsed URL.** The current
 * page is the node MenuBuilderActiveResolver marked `isActive`, and its
 * ancestors are the nodes it is nested under in the menu the editor built.
 * The obvious cheaper alternative — split the request URI on `/` and make a
 * crumb per segment — is deliberately *not* implemented, in any form, not
 * even as a fallback for when nothing matches:
 *
 * - a path segment is not a page (`/products/2024/shoes` yields a "2024"
 *   crumb that links to a 404),
 * - the URL structure and the navigation structure are routinely different
 *   (a shoe lives at `/products/shoes` and hangs under "Footwear"),
 * - and it silently invents titles from slugs (`about-us` → "About Us"), so
 *   the wrong answer is indistinguishable from the right one.
 *
 * So when the menu can't answer, this service says so: the trail comes back
 * **empty**, and the caller renders no breadcrumbs. An empty trail is a
 * correct, expected result — an editor's answer to "this page isn't in the
 * menu" — not an error.
 *
 * Nothing here is cached. The trail is a function of the active state, which
 * is per-request by definition and never enters MenuBuilderCacheService
 * (ARCHITECTURE.md "Caching"); building it is a walk over an already-built
 * tree, so there is nothing to cache but the tree itself, which already is.
 */
class MenuBuilderBreadcrumbService extends Component
{
    /**
     * The trail for a menu by handle, or null when the menu doesn't exist,
     * is disabled, or isn't available on the current site — the same three
     * outcomes, for the same reasons, as MenuBuilderResolver::getTree(), so
     * `breadcrumbs()` and `get()` never disagree about whether a menu is
     * there.
     *
     * `null` ("no such menu") and an empty trail ("this page isn't in that
     * menu") are kept distinct on purpose: the first is usually a typo in a
     * template, the second is ordinary.
     *
     * @param string|null $currentUri Overrides the page the trail is built for, exactly as
     *                                MenuBuilderResolver::getTree() does — it is the same
     *                                argument, passed straight through to the same matcher, so a
     *                                breadcrumb can never disagree with the `aria-current="page"`
     *                                the menu itself renders.
     */
    public function getTrail(string $groupHandle, ?string $currentUri = null): ?MenuBuilderBreadcrumbTrail
    {
        $tree = MenuBuilder::getInstance()->resolver->getTree($groupHandle, $currentUri);

        return $tree === null ? null : $this->trailForTree($tree);
    }

    /**
     * The trail for an already-resolved tree — what a template that has
     * already called `craft.menuBuilder.get()` should use, so one page render
     * resolves the menu once.
     *
     * Pure: it reads the `isActive` flags already on the tree and never
     * re-resolves, re-filters or re-marks anything. A tree resolved with
     * active-state marking turned off (the control-panel preview does this,
     * because it doesn't simulate a page) therefore has no current page and
     * yields an empty trail, which is the honest answer.
     */
    public function trailForTree(MenuBuilderTree $tree): MenuBuilderBreadcrumbTrail
    {
        return new MenuBuilderBreadcrumbTrail($tree->group, $this->pathToActive($tree->items, []));
    }

    /**
     * Depth-first search for the active node, carrying the path taken to
     * reach it — so the ancestors come from this tree's own nesting rather
     * than from `MenuBuilderNode::$parent`, which a caller assembling nodes
     * by hand could have left unwired, and which cannot be walked without
     * trusting it to be acyclic.
     *
     * More than one node can legitimately be the current page (the same URL
     * placed twice in one menu — "Contact" in the header and in a utility
     * strip). The first in document order wins: that is the order the editor
     * sees in the control panel and the order the menu renders in, so the
     * choice is stable across requests and explainable without reading this
     * code.
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

            // Descends unconditionally rather than only into branches
            // flagged `isActiveAncestor`. The resolver does mark every
            // ancestor of an active node, so the flag would prune correctly
            // here — but that would make the trail depend on two flags
            // agreeing with each other, where reading the one that defines
            // the current page needs no such assumption. The walk stops at
            // the first match either way.
            $found = $this->pathToActive($node->children, $path);

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }
}

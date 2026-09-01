<?php

namespace Tahadudhiya\MenuBuilder\models;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Tahadudhiya\MenuBuilder\services\MenuBuilderBreadcrumbService;
use Traversable;

/**
 * `craft.menuBuilder.breadcrumbs('main')` return value: the root-to-current
 * chain of the menu item that *is* the page being served, in that order.
 *
 * The crumbs are the very same {@see MenuBuilderNode} objects the menu
 * renders — not a parallel breadcrumb representation. That is deliberate:
 * a crumb's title, URL, clickability, active state and depth are already
 * facts of the resolved node, and a second copy of them would be a second
 * thing to keep true (and a second thing to invalidate). Anything a node
 * exposes to a `<nav>` — `title`, `url`, `isClickable`, `isActive`,
 * `level`, `custom()` — a crumb therefore exposes too.
 *
 * A trail is either empty or complete: it always begins at a top-level node
 * of the menu and ends at the node for the current page, with every
 * intermediate ancestor present. It is never assembled by splitting the
 * request URI into segments — see
 * {@see MenuBuilderBreadcrumbService} for why.
 *
 * @implements IteratorAggregate<int,MenuBuilderNode>
 */
class MenuBuilderBreadcrumbTrail implements IteratorAggregate, Countable
{
    public function __construct(
        public readonly MenuBuilderGroup $group,
        /**
         * @var MenuBuilderNode[] Root first, current page last. Empty when no
         *      item in the menu is the current page.
         */
        public readonly array $crumbs = [],
    ) {
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->crumbs);
    }

    public function count(): int
    {
        return count($this->crumbs);
    }

    /** True when nothing in this menu is the page being served — render no breadcrumbs at all. */
    public function isEmpty(): bool
    {
        return $this->crumbs === [];
    }

    /**
     * The node for the page being served — the last crumb — or null for an
     * empty trail. This is the one crumb that carries `aria-current="page"`.
     */
    public function current(): ?MenuBuilderNode
    {
        return $this->crumbs === [] ? null : $this->crumbs[count($this->crumbs) - 1];
    }

    /** The top-level node the trail descends from, or null for an empty trail. */
    public function root(): ?MenuBuilderNode
    {
        return $this->crumbs[0] ?? null;
    }

    /**
     * The trail without its last crumb — the ancestors of the current page,
     * root first. Useful for the common "… › … › **This page**" layout where
     * the current page is rendered by the page itself rather than as a link.
     *
     * @return MenuBuilderNode[]
     */
    public function ancestors(): array
    {
        return array_slice($this->crumbs, 0, -1);
    }
}

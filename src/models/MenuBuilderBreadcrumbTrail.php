<?php

namespace Tahadudhiya\MenuBuilder\models;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * `craft.menuBuilder.breadcrumbs('main')` return value: the root-to-current chain of the menu item
 * that *is* the page being served, in that order.
 *
 * @implements IteratorAggregate<int,MenuBuilderNode>
*/
class MenuBuilderBreadcrumbTrail implements IteratorAggregate, Countable
{
    public function __construct(
        public readonly MenuBuilderGroup $group,
        /**
         * @var MenuBuilderNode[] Root first, current page last. Empty when no item in the menu is the current page.
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

    /**
     * True when nothing in this menu is the page being served — render no breadcrumbs at all.
    */
    public function isEmpty(): bool
    {
        return $this->crumbs === [];
    }

    /**
     * The node for the page being served — the last crumb — or null for an empty trail.
    */
    public function current(): ?MenuBuilderNode
    {
        return $this->crumbs === [] ? null : $this->crumbs[count($this->crumbs) - 1];
    }

    /**
     * The top-level node the trail descends from, or null for an empty trail.
    */
    public function root(): ?MenuBuilderNode
    {
        return $this->crumbs[0] ?? null;
    }

    /**
     * The trail without its last crumb — the ancestors of the current page, root first.
     *
     * @return MenuBuilderNode[]
    */
    public function ancestors(): array
    {
        return array_slice($this->crumbs, 0, -1);
    }
}

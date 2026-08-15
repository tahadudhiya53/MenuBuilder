<?php

namespace Tahadudhiya\MenuBuilder\models;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * `craft.menuBuilder.get('main')` return value. Iterable directly over its
 * top-level nodes so `{% for item in menu %}` works without a
 * `.items` accessor, while `.items`/`.group` remain available for anyone who
 * wants them explicitly.
 *
 * @implements IteratorAggregate<int,MenuBuilderNode>
 */
class MenuBuilderTree implements IteratorAggregate, Countable
{
    public function __construct(
        public readonly MenuBuilderGroup $group,
        /** @var MenuBuilderNode[] */
        public readonly array $items,
    ) {
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Depth-first flat walk of every node in the tree (useful for search/
     * "find the active item" without recursing in Twig).
     *
     * @return MenuBuilderNode[]
     */
    public function flatten(): array
    {
        $flatten = function(array $nodes) use (&$flatten): array {
            $result = [];
            foreach ($nodes as $node) {
                $result[] = $node;
                $result = [...$result, ...$flatten($node->children)];
            }

            return $result;
        };

        return $flatten($this->items);
    }
}

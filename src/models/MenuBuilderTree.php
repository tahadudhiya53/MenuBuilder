<?php

namespace Tahadudhiya\MenuBuilder\models;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Tahadudhiya\MenuBuilder\helpers\MobileHelper;
use Traversable;

/**
 * `craft.menuBuilder.get('main')` return value.
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
     * The same menu as it belongs in one viewport: items restricted to the other one removed, and
     * mobile order applied when the viewport is mobile.
    */
    public function forViewport(string $viewport): self
    {
        return new self($this->group, $this->shapeForViewport($this->items, $viewport));
    }

    /**
     * @param MenuBuilderNode[] $nodes
     * @return MenuBuilderNode[]
    */
    private function shapeForViewport(array $nodes, string $viewport): array
    {
        $shaped = [];

        foreach ($nodes as $node) {
            if (!$node->isVisibleOn($viewport)) {
                continue;
            }

            $shaped[] = $node->withChildren(
                $this->shapeForViewport($node->children, $viewport),
                preserveActiveState: true,
            );
        }

        if ($viewport === MobileHelper::VIEWPORT_MOBILE) {
            $shaped = self::sortByMobileOrder($shaped);
        }

        return $shaped;
    }

    /**
     * Stable sort of one sibling list by mobile order.
     *
     * @param MenuBuilderNode[] $nodes
     * @return MenuBuilderNode[]
    */
    private static function sortByMobileOrder(array $nodes): array
    {
        usort($nodes, static function(MenuBuilderNode $a, MenuBuilderNode $b): int {
            $left = $a->mobileOrder();
            $right = $b->mobileOrder();

            if ($left === $right) {
                return 0;
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            return $left <=> $right;
        });

        return $nodes;
    }

    /**
     * Depth-first flat walk of every node in the tree (useful for search/ "find the active item"
     * without recursing in Twig).
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

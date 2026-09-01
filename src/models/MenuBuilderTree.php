<?php

namespace Tahadudhiya\MenuBuilder\models;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Tahadudhiya\MenuBuilder\helpers\MobileHelper;
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
     * The same menu as it belongs in one viewport: items restricted to the
     * other one removed, and mobile order applied when the viewport is
     * mobile.
     *
     *     {% set menu = craft.menuBuilder.get('main') %}
     *     {{ menuMacros.renderNav(menu.forViewport('desktop'), null, 'details', 'desktop', 'desktop') }}
     *     {{ menuMacros.renderNav(menu.forViewport('mobile'), 'Menu'|t, 'details', 'mobile', 'mobile') }}
     *
     * **One resolve, two trees.** This is a pure re-shaping of an
     * already-resolved tree: no query, no cache read, no link resolution and
     * no visibility evaluation happens here, so rendering both navigations on
     * a page costs one resolve and not two. That is the whole reason mobile
     * is item metadata rather than a second menu — see {@see MobileHelper}.
     *
     * **Why re-sorting rather than a CSS `order`.** The mobile order has to
     * be the DOM order, or the visual sequence and the Tab/screen-reader
     * sequence disagree (WCAG 1.3.2, 2.4.3). Sorting is stable and only
     * *among the items that set an order*: an item with an order sits at its
     * number, and the rest keep the sequence the editor dragged them into.
     * The alternative — treating "no order" as 0 — would shuffle every
     * unconfigured item to the top the moment one sibling got a number.
     *
     * An unknown viewport string filters nothing and sorts nothing, so a typo
     * renders the menu rather than an empty landmark; see
     * {@see MobileHelper::isVisibleOn()}.
     *
     * Active state is carried across (the tree it copies has already been
     * marked), and every node is copied rather than mutated, for the same
     * reason MenuBuilderResolver copies: these objects can be the cached
     * ones. See {@see MenuBuilderNode::withChildren()}.
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
     * Stable sort of one sibling list by mobile order. Items without an
     * order keep their editor-dragged position relative to each other and
     * sort after the numbered ones — `usort` is stable in PHP 8, so the
     * comparison only has to answer the ordered/unordered cases.
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

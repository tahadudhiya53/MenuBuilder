<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * MenuBuilderNode and MenuBuilderTree — the immutable, user-independent shape
 * that gets cached, plus the pure grouping logic layered on top of it. All of
 * it runs against hand-built nodes with no database.
 */
class MenuBuilderNodeTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Immutability of the cached node tree
    // ---------------------------------------------------------------------

    private function node(
        int $id,
        string $title = 'Node',
        ?int $column = null,
        ?MenuBuilderMegaMenuConfig $megaMenu = null,
    ): MenuBuilderNode {
        return new MenuBuilderNode(
            id: $id,
            handle: null,
            type: 'url',
            title: $title,
            url: '/' . $id,
            isClickable: true,
            isLinkAvailable: true,
            target: '_self',
            rel: null,
            cssClass: 'nav-item',
            htmlId: null,
            htmlAttributes: ['data-id' => (string)$id],
            ariaLabel: null,
            titleAttribute: null,
            icon: null,
            badge: null,
            description: null,
            image: null,
            featured: false,
            level: 1,
            megaMenu: $megaMenu,
            megaMenuColumn: $column,
        );
    }

    public function testWithChildrenLeavesTheSourceNodeUntouched(): void
    {
        $cached = $this->node(1);
        $cached->children = [$this->node(2), $this->node(3)];

        $copy = $cached->withChildren([$this->node(2)]);

        $this->assertCount(2, $cached->children, 'The cached node must keep every child.');
        $this->assertCount(1, $copy->children);
        $this->assertNotSame($cached, $copy);
    }

    public function testWithChildrenCarriesEveryReadonlyValueAcross(): void
    {
        $cached = $this->node(7, 'Products');
        $copy = $cached->withChildren([]);

        $this->assertSame(7, $copy->id);
        $this->assertSame('Products', $copy->title);
        $this->assertSame('/7', $copy->url);
        $this->assertSame('nav-item', $copy->cssClass);
        $this->assertSame(['data-id' => '7'], $copy->htmlAttributes);
        $this->assertSame(1, $copy->level);
        $this->assertTrue($copy->isClickable);
    }

    /** Otherwise a child's `parent` would still point into the cached tree. */
    public function testWithChildrenRewiresChildParentsToTheCopy(): void
    {
        $child = $this->node(2);
        $copy = $this->node(1)->withChildren([$child]);

        $this->assertSame($copy, $child->parent);
    }

    /**
     * Active state is the other half of the per-request pipeline: a copy
     * starts clean so a previous request's marking can never survive on a
     * cached node and leak through.
     */
    public function testWithChildrenResetsActiveState(): void
    {
        $cached = $this->node(1);
        $cached->isActive = true;
        $cached->isActiveAncestor = true;

        $copy = $cached->withChildren([]);

        $this->assertFalse($copy->isActive);
        $this->assertFalse($copy->isActiveAncestor);
        $this->assertTrue($cached->isActive, 'The source node is not modified.');
    }

    public function testFlattenWalksTheWholeTreeDepthFirst(): void
    {
        $grandchild = $this->node(3);
        $child = $this->node(2);
        $child->children = [$grandchild];
        $root = $this->node(1);
        $root->children = [$child];
        $sibling = $this->node(4);

        $tree = new MenuBuilderTree(new MenuBuilderGroup(), [$root, $sibling]);

        $this->assertSame([1, 2, 3, 4], array_map(fn(MenuBuilderNode $n) => $n->id, $tree->flatten()));
        $this->assertCount(2, $tree, 'count() reports top-level nodes only.');
        $this->assertSame([1, 4], array_map(fn(MenuBuilderNode $n) => $n->id, iterator_to_array($tree)));
    }

    // ---------------------------------------------------------------------
    // Mega-menu column grouping
    // ---------------------------------------------------------------------

    public function testChildrenGroupedByConfiguredColumn(): void
    {
        $parent = $this->node(1, megaMenu: new MenuBuilderMegaMenuConfig(columns: 3));
        $parent->children = [
            $this->node(2, column: 2),
            $this->node(3, column: 1),
            $this->node(4, column: 2),
        ];

        $columns = $parent->megaMenuColumns();

        $this->assertSame([1, 2], array_keys($columns));
        $this->assertSame([3], array_map(fn($n) => $n->id, $columns[1]));
        $this->assertSame([2, 4], array_map(fn($n) => $n->id, $columns[2]));
    }

    public function testUnsetOrOutOfRangeColumnCollapsesIntoColumnOne(): void
    {
        $parent = $this->node(1, megaMenu: new MenuBuilderMegaMenuConfig(columns: 2));
        $parent->children = [
            $this->node(2, column: null),
            $this->node(3, column: 99),
        ];

        $columns = $parent->megaMenuColumns();

        $this->assertSame([1], array_keys($columns));
        $this->assertCount(2, $columns[1]);
    }

    public function testNoMegaMenuConfigDefaultsToOneColumn(): void
    {
        $parent = $this->node(1);
        $parent->children = [$this->node(2, column: 5)];

        $columns = $parent->megaMenuColumns();

        $this->assertSame([1], array_keys($columns));
    }
}

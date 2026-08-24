<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;

/**
 * Covers MenuBuilderNode::megaMenuColumns() — pure grouping logic, no DB
 * access, exercised directly against hand-built nodes (Phase 7).
 */
class MenuBuilderNodeMegaMenuTest extends TestCase
{
    private function makeNode(int $id, ?int $column = null, ?MenuBuilderMegaMenuConfig $megaMenu = null): MenuBuilderNode
    {
        return new MenuBuilderNode(
            id: $id,
            handle: null,
            type: 'url',
            title: "Node $id",
            url: '/node-' . $id,
            isClickable: true,
            isLinkAvailable: true,
            target: '_self',
            rel: null,
            cssClass: null,
            htmlId: null,
            htmlAttributes: [],
            ariaLabel: null,
            titleAttribute: null,
            icon: null,
            badge: null,
            description: null,
            image: null,
            featured: false,
            level: 2,
            megaMenu: $megaMenu,
            megaMenuColumn: $column,
        );
    }

    public function testChildrenGroupedByConfiguredColumn(): void
    {
        $parent = $this->makeNode(1, megaMenu: new MenuBuilderMegaMenuConfig(columns: 3));
        $parent->children = [
            $this->makeNode(2, column: 2),
            $this->makeNode(3, column: 1),
            $this->makeNode(4, column: 2),
        ];

        $columns = $parent->megaMenuColumns();

        $this->assertSame([1, 2], array_keys($columns));
        $this->assertSame([3], array_map(fn($n) => $n->id, $columns[1]));
        $this->assertSame([2, 4], array_map(fn($n) => $n->id, $columns[2]));
    }

    public function testUnsetOrOutOfRangeColumnCollapsesIntoColumnOne(): void
    {
        $parent = $this->makeNode(1, megaMenu: new MenuBuilderMegaMenuConfig(columns: 2));
        $parent->children = [
            $this->makeNode(2, column: null),
            $this->makeNode(3, column: 99),
        ];

        $columns = $parent->megaMenuColumns();

        $this->assertSame([1], array_keys($columns));
        $this->assertCount(2, $columns[1]);
    }

    public function testNoMegaMenuConfigDefaultsToOneColumn(): void
    {
        $parent = $this->makeNode(1);
        $parent->children = [$this->makeNode(2, column: 5)];

        $columns = $parent->megaMenuColumns();

        $this->assertSame([1], array_keys($columns));
    }
}

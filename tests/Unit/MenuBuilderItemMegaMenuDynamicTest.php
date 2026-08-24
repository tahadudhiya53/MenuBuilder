<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * Covers MenuBuilderItem::validateMegaMenu()/validateDynamicSource() —
 * fail-closed shape validation for the metadata bag, same pattern as the
 * existing visibility validation tests.
 */
class MenuBuilderItemMegaMenuDynamicTest extends TestCase
{
    private function makeUrlItem(): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Test';
        $item->customUrl = '/somewhere';

        return $item;
    }

    public function testMegaMenuEnabledRequiresValidColumnsRange(): void
    {
        $item = $this->makeUrlItem();
        $item->metadata = ['megaMenu' => ['enabled' => true, 'columns' => 0]];
        $this->assertFalse($item->validate());

        $item->metadata = ['megaMenu' => ['enabled' => true, 'columns' => 7]];
        $this->assertFalse($item->validate());

        $item->metadata = ['megaMenu' => ['enabled' => true, 'columns' => 3]];
        $this->assertTrue($item->validate());
    }

    public function testMegaMenuMustBeAnObject(): void
    {
        $item = $this->makeUrlItem();
        $item->metadata = ['megaMenu' => 'not-an-array'];

        $this->assertFalse($item->validate());
    }

    public function testMegaMenuColumnMustBeInRange(): void
    {
        $item = $this->makeUrlItem();
        $item->metadata = ['megaMenuColumn' => 0];
        $this->assertFalse($item->validate());

        $item->metadata = ['megaMenuColumn' => 7];
        $this->assertFalse($item->validate());

        $item->metadata = ['megaMenuColumn' => 2];
        $this->assertTrue($item->validate());
    }

    public function testDynamicTypeRequiresSourceConfig(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';

        $this->assertFalse($item->validate());
        $this->assertNotEmpty($item->getErrors('metadata'));
    }

    public function testDynamicSourceRejectsUnknownSourceType(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';
        $item->metadata = ['dynamicSource' => ['sourceType' => 'users', 'sourceId' => 1]];

        $this->assertFalse($item->validate());
    }

    public function testDynamicSourceRejectsInvalidSourceId(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';
        $item->metadata = ['dynamicSource' => ['sourceType' => 'entries', 'sourceId' => -1]];

        $this->assertFalse($item->validate());
    }

    public function testDynamicSourceRejectsUnknownOrderBy(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';
        $item->metadata = ['dynamicSource' => ['sourceType' => 'entries', 'sourceId' => 1, 'orderBy' => 'RAND()']];

        $this->assertFalse($item->validate());
    }

    public function testValidDynamicSourcePasses(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';
        $item->metadata = ['dynamicSource' => ['sourceType' => 'entries', 'sourceId' => 1, 'limit' => 10, 'orderBy' => 'title asc']];

        $this->assertTrue($item->validate());
    }

    public function testDynamicSourceLimitMustBePositiveInteger(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';
        $item->metadata = ['dynamicSource' => ['sourceType' => 'entries', 'sourceId' => 1, 'limit' => 0]];

        $this->assertFalse($item->validate());
    }
}

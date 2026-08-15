<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

class MenuBuilderItemModelTest extends TestCase
{
    public function testTitleRequiredUnlessSeparator(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = '';
        $item->customUrl = '/foo';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('title', $item->getErrors());

        $separator = new MenuBuilderItem();
        $separator->groupId = 1;
        $separator->type = MenuBuilderItem::TYPE_SEPARATOR;
        $separator->title = '';

        $this->assertTrue($separator->validate());
    }

    public function testElementIdRequiredForElementTypes(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_ENTRY;
        $item->title = 'An entry link';
        $item->elementId = null;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('elementId', $item->getErrors());
    }

    public function testCustomUrlValidation(): void
    {
        $valid = ['/relative/path', 'https://example.com', '#section', 'mailto:test@example.com', 'tel:+123456789', '/path?query=1#frag'];
        $invalid = ['', 'not a url', 'javascript:alert(1)'];

        foreach ($valid as $url) {
            $this->assertTrue(MenuBuilderItem::isPermissiveUrl($url), "Expected valid: $url");
        }

        foreach ($invalid as $url) {
            $this->assertFalse(MenuBuilderItem::isPermissiveUrl($url), "Expected invalid: $url");
        }
    }

    public function testFallbackUrlRequiredWhenFallbackBehaviorIsFallbackUrl(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = '/foo';
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_FALLBACK_URL;
        $item->fallbackUrl = null;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('fallbackUrl', $item->getErrors());
    }

    public function testIsLinkable(): void
    {
        $entry = new MenuBuilderItem();
        $entry->type = MenuBuilderItem::TYPE_ENTRY;
        $this->assertTrue($entry->isLinkable());

        $heading = new MenuBuilderItem();
        $heading->type = MenuBuilderItem::TYPE_NONCLICKABLE;
        $this->assertFalse($heading->isLinkable());

        $separator = new MenuBuilderItem();
        $separator->type = MenuBuilderItem::TYPE_SEPARATOR;
        $this->assertFalse($separator->isLinkable());
    }

    public function testHasChildren(): void
    {
        $item = new MenuBuilderItem();
        $this->assertFalse($item->hasChildren());

        $item->children = [new MenuBuilderItem()];
        $this->assertTrue($item->hasChildren());
    }
}

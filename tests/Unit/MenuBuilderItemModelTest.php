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

    public function testTitleOptionalForElementBackedTypes(): void
    {
        foreach ([MenuBuilderItem::TYPE_ENTRY, MenuBuilderItem::TYPE_CATEGORY, MenuBuilderItem::TYPE_ASSET] as $type) {
            $item = new MenuBuilderItem();
            $item->groupId = 1;
            $item->type = $type;
            $item->title = '';
            $item->elementId = 42;

            $this->assertTrue($item->validate(), "Expected blank title to be valid for type $type: " . json_encode($item->getErrors()));
        }
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

    public function testCustomUrlRequiredForUrlType(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = null;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('customUrl', $item->getErrors());
    }

    public function testAnchorTargetRequired(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_ANCHOR;
        $item->title = 'Jump link';
        $item->handle = null;
        $item->customUrl = null;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('handle', $item->getErrors());

        $item->handle = 'section-2';
        $this->assertTrue($item->validate());
    }

    public function testAnchorTargetRejectsMalformedFragments(): void
    {
        foreach (['has space', "quo\"te", "sing'le", '<tag>', '#'] as $invalid) {
            $this->assertFalse(MenuBuilderItem::isValidAnchorTarget($invalid), "Expected invalid: $invalid");
        }

        foreach (['features', '#features', 'section-2', 'a.b:c'] as $valid) {
            $this->assertTrue(MenuBuilderItem::isValidAnchorTarget($valid), "Expected valid: $valid");
        }

        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_ANCHOR;
        $item->title = 'Jump link';
        $item->handle = 'has space';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('handle', $item->getErrors());
    }

    public function testHtmlAttributesRejectsEventHandlerKeys(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = '/foo';
        $item->htmlAttributes = ['onclick' => 'doSomething()'];

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('htmlAttributes', $item->getErrors());
    }

    public function testHtmlAttributesRejectsJavascriptUrls(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = '/foo';
        $item->htmlAttributes = ['data-href' => 'java script:alert(1)'];

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('htmlAttributes', $item->getErrors());
    }

    public function testHtmlAttributesAllowsSafeDataAttributes(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = '/foo';
        $item->htmlAttributes = ['data-tracking' => 'nav-main'];

        $this->assertTrue($item->validate());
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

    /**
     * A non-clickable heading/separator must never become clickable just
     * because a customUrl or a stale clickable=true value is present on the
     * item — isLinkable() is authoritative regardless of those values.
     */
    public function testNonClickableAndSeparatorStayUnlinkableEvenWithCustomUrl(): void
    {
        $heading = new MenuBuilderItem();
        $heading->type = MenuBuilderItem::TYPE_NONCLICKABLE;
        $heading->customUrl = '/products';
        $heading->clickable = true;

        $this->assertFalse($heading->isLinkable());

        $separator = new MenuBuilderItem();
        $separator->type = MenuBuilderItem::TYPE_SEPARATOR;
        $separator->customUrl = '/products';
        $separator->clickable = true;

        $this->assertFalse($separator->isLinkable());
    }

    public function testSeparatorRequiresNoLinkConfiguration(): void
    {
        $separator = new MenuBuilderItem();
        $separator->groupId = 1;
        $separator->type = MenuBuilderItem::TYPE_SEPARATOR;

        $this->assertTrue($separator->validate(), 'A separator should validate with no title, URL, or element set: ' . json_encode($separator->getErrors()));
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

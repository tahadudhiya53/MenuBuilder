<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\linktypes\AnchorLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\NonClickableLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\UrlLinkResolver;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * ElementLinkResolver (entry/category/asset) needs a booted Craft app/DB to
 * query elements and is exercised via manual/integration testing instead
 * (see plugin CLAUDE.md-equivalent notes) — these cover the link types that
 * are pure PHP logic.
 */
class LinkTypeResolverTest extends TestCase
{
    public function testUrlLinkResolver(): void
    {
        $resolver = new UrlLinkResolver();

        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = '/contact';

        $link = $resolver->resolve($item);
        $this->assertTrue($link->isAvailable);
        $this->assertSame('/contact', $link->url);

        $empty = new MenuBuilderItem();
        $empty->type = MenuBuilderItem::TYPE_URL;
        $this->assertFalse($resolver->resolve($empty)->isAvailable);
    }

    public function testAnchorLinkResolver(): void
    {
        $resolver = new AnchorLinkResolver();

        $item = new MenuBuilderItem();
        $item->handle = 'section-2';

        $this->assertSame('#section-2', $resolver->resolve($item)->url);

        $withHash = new MenuBuilderItem();
        $withHash->handle = '#already-hashed';
        $this->assertSame('#already-hashed', $resolver->resolve($withHash)->url);

        $empty = new MenuBuilderItem();
        $this->assertFalse($resolver->resolve($empty)->isAvailable);
    }

    public function testNonClickableLinkResolver(): void
    {
        $resolver = new NonClickableLinkResolver();

        $heading = new MenuBuilderItem();
        $heading->type = MenuBuilderItem::TYPE_NONCLICKABLE;
        $heading->clickable = false;

        $link = $resolver->resolve($heading);
        $this->assertTrue($link->isAvailable);
        $this->assertNull($link->url);
    }

    /**
     * There is no "give it a link anyway" path — a customUrl or a stale
     * clickable=true on a heading/separator item must never produce a link.
     */
    public function testNonClickableLinkResolverIgnoresCustomUrlAndClickableFlag(): void
    {
        $resolver = new NonClickableLinkResolver();

        $headingWithUrl = new MenuBuilderItem();
        $headingWithUrl->type = MenuBuilderItem::TYPE_NONCLICKABLE;
        $headingWithUrl->clickable = true;
        $headingWithUrl->customUrl = '/products';

        $link = $resolver->resolve($headingWithUrl);
        $this->assertNull($link->url);
        $this->assertTrue($link->isAvailable);
    }

    public function testSeparatorRemainsStructuralEvenWithCustomUrl(): void
    {
        $resolver = new NonClickableLinkResolver();

        $separator = new MenuBuilderItem();
        $separator->type = MenuBuilderItem::TYPE_SEPARATOR;
        $separator->clickable = true;
        $separator->customUrl = '/products';

        $link = $resolver->resolve($separator);
        $this->assertNull($link->url);
        $this->assertTrue($link->isAvailable);
    }
}

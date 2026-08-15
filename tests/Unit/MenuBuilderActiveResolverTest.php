<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\services\MenuBuilderActiveResolver;

class MenuBuilderActiveResolverTest extends TestCase
{
    private function node(int $id, ?string $url, array $children = []): MenuBuilderNode
    {
        $node = new MenuBuilderNode(
            id: $id,
            handle: null,
            type: 'url',
            title: "Item $id",
            url: $url,
            isClickable: $url !== null,
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
            level: 1,
        );
        $node->children = $children;

        return $node;
    }

    public function testExactMatchMarksActive(): void
    {
        $productA = $this->node(2, '/products/product-a');
        $products = $this->node(1, null, [$productA]);

        (new MenuBuilderActiveResolver())->mark([$products], '/products/product-a');

        $this->assertTrue($productA->isActive);
        $this->assertFalse($products->isActive);
        $this->assertTrue($products->isActiveAncestor);
    }

    public function testUnrelatedPageMarksNothing(): void
    {
        $productA = $this->node(2, '/products/product-a');
        $products = $this->node(1, null, [$productA]);

        (new MenuBuilderActiveResolver())->mark([$products], '/contact');

        $this->assertFalse($productA->isActive);
        $this->assertFalse($products->isActiveAncestor);
    }

    public function testTrailingSlashAndQueryStringIgnored(): void
    {
        $home = $this->node(1, '/');

        (new MenuBuilderActiveResolver())->mark([$home], '/?utm_source=test');

        $this->assertTrue($home->isActive);
    }
}

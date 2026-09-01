<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\services\MenuBuilderActiveResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderResolver;
use Tahadudhiya\MenuBuilder\variables\MenuBuilderVariable;

class MenuBuilderActiveResolverTest extends TestCase
{
    private const SITE_HOSTS = ['example.test', 'de.example.test'];

    private function node(
        int $id,
        ?string $url,
        array $children = [],
        string $type = MenuBuilderItem::TYPE_URL,
        bool $isLinkAvailable = true,
        int $level = 1,
    ): MenuBuilderNode {
        $node = new MenuBuilderNode(
            id: $id,
            handle: null,
            type: $type,
            title: "Item $id",
            url: $url,
            isClickable: $url !== null,
            isLinkAvailable: $isLinkAvailable,
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
            level: $level,
        );
        $node->children = $children;

        foreach ($children as $child) {
            $child->parent = $node;
        }

        return $node;
    }

    private function mark(array $topLevelNodes, string $currentUri): void
    {
        (new MenuBuilderActiveResolver())->mark($topLevelNodes, $currentUri, self::SITE_HOSTS);
    }

    /**
     * The phase's worked example: on /products/shoes, "Shoes" is active,
     * "Products" is an active ancestor, and nothing else is either.
     */
    private function productsTree(): array
    {
        $shoes = $this->node(4, '/products/shoes', level: 3);
        $boots = $this->node(5, '/products/boots', level: 3);
        $footwear = $this->node(3, '/products', [$shoes, $boots], level: 2);
        $products = $this->node(2, null, [$footwear], type: MenuBuilderItem::TYPE_NONCLICKABLE);
        $contact = $this->node(6, '/contact');

        return [$products, $footwear, $shoes, $boots, $contact];
    }

    /** @return MenuBuilderNode[] */
    private function activeNodes(array $nodes): array
    {
        $active = [];

        foreach ($nodes as $node) {
            if ($node->isActive) {
                $active[] = $node;
            }
            $active = [...$active, ...$this->activeNodes($node->children)];
        }

        return $active;
    }

    // ---------------------------------------------------------------- hierarchy

    public function testExactUriMarksTheItemActive(): void
    {
        [$products, $footwear, $shoes, $boots, $contact] = $this->productsTree();

        $this->mark([$products, $contact], '/products/shoes');

        $this->assertTrue($shoes->isActive);
        $this->assertFalse($shoes->isActiveAncestor);
        $this->assertTrue($shoes->isActiveOrAncestor());
    }

    public function testParentIsAnActiveAncestorButNotActive(): void
    {
        [$products, $footwear, $shoes, $boots, $contact] = $this->productsTree();

        $this->mark([$products, $contact], '/products/shoes');

        $this->assertFalse($footwear->isActive);
        $this->assertTrue($footwear->isActiveAncestor);
        $this->assertTrue($footwear->isActiveOrAncestor());
    }

    public function testGrandparentIsAnActiveAncestor(): void
    {
        [$products, $footwear, $shoes, $boots, $contact] = $this->productsTree();

        $this->mark([$products, $contact], '/products/shoes');

        $this->assertFalse($products->isActive);
        $this->assertTrue($products->isActiveAncestor);
        $this->assertTrue($products->isActiveOrAncestor());
    }

    public function testSiblingAndUnrelatedBranchStayInactive(): void
    {
        [$products, $footwear, $shoes, $boots, $contact] = $this->productsTree();

        $this->mark([$products, $contact], '/products/shoes');

        foreach ([$boots, $contact] as $node) {
            $this->assertFalse($node->isActive);
            $this->assertFalse($node->isActiveAncestor);
            $this->assertFalse($node->isActiveOrAncestor());
        }
    }

    /**
     * The data-level guarantee behind `aria-current="page"`: exactly one node
     * in the tree is `isActive`, so the attribute can only ever land on the
     * link for the page actually being served — an active ancestor carries
     * `isActiveAncestor` instead.
     *
     * The template half — that the bundled macro emits the attribute for
     * `isActive` alone, and that nothing else can add a second one — is
     * asserted against rendered markup in
     * {@see MenuBuilderAccessibilityTest}, rather than by reading the
     * template's source.
     */
    public function testOnlyOneNodeInTheTreeIsActive(): void
    {
        [$products, $footwear, $shoes, $boots, $contact] = $this->productsTree();

        $this->mark([$products, $contact], '/products/shoes');

        $this->assertSame([$shoes], $this->activeNodes([$products, $contact]));
    }

    public function testUnrelatedPageMarksNothing(): void
    {
        [$products, $footwear, $shoes, $boots, $contact] = $this->productsTree();

        $this->mark([$products, $contact], '/about');

        $this->assertSame([], $this->activeNodes([$products, $contact]));
        $this->assertFalse($products->isActiveAncestor);
        $this->assertFalse($footwear->isActiveAncestor);
    }

    public function testSimilarPrefixIsNotTreatedAsAMatch(): void
    {
        $news = $this->node(1, '/news');

        $this->mark([$news], 'newsletter');

        $this->assertFalse($news->isActive);
    }

    public function testDeeperPageDoesNotActivateTheParentItemItself(): void
    {
        $shoes = $this->node(2, '/products/shoes', level: 2);
        $products = $this->node(1, '/products', [$shoes]);

        $this->mark([$products], '/products/shoes/laces');

        $this->assertFalse($shoes->isActive);
        $this->assertFalse($products->isActive);
        $this->assertFalse($products->isActiveAncestor);
    }

    // ------------------------------------------------------------------- URIs

    /**
     * Craft's own Request::getFullUri() has no leading slash, while an item's
     * URL almost always does — so this is the shape every real request takes,
     * and the case that once regressed active state entirely.
     */
    public function testCurrentUriWithoutLeadingSlashStillMatches(): void
    {
        $about = $this->node(2, '/about', level: 2);
        $parent = $this->node(1, null, [$about]);

        $this->mark([$parent], 'about');

        $this->assertTrue($about->isActive);
        $this->assertTrue($parent->isActiveAncestor);
    }

    public function testItemWithoutLeadingSlashMatchesAbsoluteCurrentUri(): void
    {
        $contact = $this->node(1, 'contact');

        $this->mark([$contact], '/contact');

        $this->assertTrue($contact->isActive);
    }

    public function testHomepageIsActiveOnTheHomepage(): void
    {
        foreach (['', '/', '/?utm_source=test', 'https://example.test/'] as $currentUri) {
            foreach (['/', 'https://example.test/', 'https://example.test'] as $url) {
                $home = $this->node(1, $url);

                $this->mark([$home], $currentUri);

                $this->assertTrue($home->isActive, "URL $url on current URI '$currentUri'");
            }
        }
    }

    public function testHomepageIsNotActiveOnAnInnerPage(): void
    {
        $home = $this->node(1, '/');
        $about = $this->node(2, '/about');

        $this->mark([$home, $about], 'about');

        $this->assertFalse($home->isActive);
        $this->assertTrue($about->isActive);
    }

    public function testTrailingSlashOnEitherSideIsIgnored(): void
    {
        foreach (['/products/shoes', '/products/shoes/'] as $url) {
            foreach (['products/shoes', '/products/shoes/', 'https://example.test/products/shoes/'] as $currentUri) {
                $shoes = $this->node(1, $url);

                $this->mark([$shoes], $currentUri);

                $this->assertTrue($shoes->isActive, "URL $url on current URI '$currentUri'");
            }
        }
    }

    public function testQueryParametersAreIgnoredOnBothSides(): void
    {
        $search = $this->node(1, '/search?q=shoes');

        $this->mark([$search], '/search?q=boots&page=2');

        $this->assertTrue($search->isActive);
    }

    public function testFragmentsAreIgnoredOnBothSides(): void
    {
        $shoes = $this->node(1, '/products/shoes#reviews');

        $this->mark([$shoes], '/products/shoes#gallery');

        $this->assertTrue($shoes->isActive);
    }

    /**
     * An anchor item is a jump to a position on a page, not a page of its own,
     * so it never becomes the active item — and in particular a bare fragment
     * must not collapse to "/" and light up on the homepage.
     */
    public function testAnchorOnlyItemIsNeverActive(): void
    {
        foreach (['', '/', '/#top', '/about'] as $currentUri) {
            $anchor = $this->node(1, '#top', type: MenuBuilderItem::TYPE_ANCHOR);

            $this->mark([$anchor], $currentUri);

            $this->assertFalse($anchor->isActive, "current URI '$currentUri'");
        }
    }

    // -------------------------------------------------------------- link types

    public function testCustomRelativeUrlMatches(): void
    {
        $custom = $this->node(1, '/products/shoes');

        $this->mark([$custom], 'products/shoes');

        $this->assertTrue($custom->isActive);
    }

    public function testAbsoluteEntryUrlMatchesRelativeCurrentUri(): void
    {
        $entry = $this->node(1, 'https://example.test/news/latest', type: MenuBuilderItem::TYPE_ENTRY);

        $this->mark([$entry], 'news/latest');

        $this->assertTrue($entry->isActive);
    }

    public function testAbsoluteCategoryUrlMatchesRelativeCurrentUri(): void
    {
        $category = $this->node(1, 'https://example.test/categories/footwear/', type: MenuBuilderItem::TYPE_CATEGORY);

        $this->mark([$category], 'categories/footwear');

        $this->assertTrue($category->isActive);
    }

    public function testAssetUrlMatchesTheRequestedFilePath(): void
    {
        $asset = $this->node(1, 'https://example.test/uploads/brochure.pdf', type: MenuBuilderItem::TYPE_ASSET);

        $this->mark([$asset], 'uploads/brochure.pdf');

        $this->assertTrue($asset->isActive);
    }

    /**
     * A second site's own domain still counts as this install (it's in the
     * internal-host list), so a cross-site link resolves active state normally
     * when that site is the one being served.
     */
    public function testUrlOnAnotherSiteOfTheSameInstallMatchesThatSitesRequest(): void
    {
        $de = $this->node(1, 'https://de.example.test/produkte/schuhe', type: MenuBuilderItem::TYPE_ENTRY);

        $this->mark([$de], 'https://de.example.test/produkte/schuhe');

        $this->assertTrue($de->isActive);
    }

    public function testExternalHostIsNeverActiveEvenWhenThePathMatches(): void
    {
        foreach ([
            'https://shop.elsewhere.test/products/shoes',
            'http://elsewhere.test/products/shoes',
            '//elsewhere.test/products/shoes',
        ] as $url) {
            $external = $this->node(1, $url);

            $this->mark([$external], '/products/shoes');

            $this->assertFalse($external->isActive, "URL $url");
        }
    }

    public function testNonNavigableSchemesAreNeverActive(): void
    {
        foreach (['mailto:hello@example.test', 'tel:+441234567890'] as $url) {
            $node = $this->node(1, $url);

            $this->mark([$node], parse_url($url, PHP_URL_PATH));

            $this->assertFalse($node->isActive, "URL $url");
        }
    }

    /**
     * "Disable link" leaves the item rendered as a label with no destination,
     * and a rejected/blank custom URL resolves the same way — neither is a page
     * that can be current.
     */
    public function testUnavailableOrEmptyLinkIsNeverActive(): void
    {
        $unavailable = $this->node(2, '/products/shoes', isLinkAvailable: false, level: 2);
        $blank = $this->node(3, '   ', level: 2);
        $noUrl = $this->node(4, null, level: 2);
        $parent = $this->node(1, null, [$unavailable, $blank, $noUrl]);

        $this->mark([$parent], '/products/shoes');

        $this->assertFalse($unavailable->isActive);
        $this->assertFalse($blank->isActive);
        $this->assertFalse($noUrl->isActive);
        $this->assertFalse($parent->isActiveAncestor);
    }

    // ------------------------------------------------- currentUri override

    public function testCurrentUriOverrideIsHonouredAndReplacesPreviousMarking(): void
    {
        [$products, $footwear, $shoes, $boots, $contact] = $this->productsTree();
        $resolver = new MenuBuilderActiveResolver();

        $resolver->mark([$products, $contact], '/products/shoes', self::SITE_HOSTS);
        $this->assertSame([$shoes], $this->activeNodes([$products, $contact]));

        // Marking again with a different URI must recompute every flag, not
        // accumulate: the previously active node and its ancestors go back to
        // false without the caller having to reset anything.
        $resolver->mark([$products, $contact], '/products/boots', self::SITE_HOSTS);

        $this->assertSame([$boots], $this->activeNodes([$products, $contact]));
        $this->assertFalse($shoes->isActive);
        $this->assertTrue($footwear->isActiveAncestor);
        $this->assertTrue($products->isActiveAncestor);
    }

    /**
     * The override has to be reachable from Twig
     * (`craft.menuBuilder.get('main', '/products/shoes')`) — asserted on the
     * signatures, since exercising the pipeline needs a booted Craft app.
     */
    public function testCurrentUriOverrideIsExposedThroughTheTwigApi(): void
    {
        foreach ([[MenuBuilderVariable::class, 'get'], [MenuBuilderResolver::class, 'getTree']] as [$class, $method]) {
            $parameter = (new \ReflectionMethod($class, $method))->getParameters()[1] ?? null;

            $this->assertNotNull($parameter, "$class::$method() has no second parameter");
            $this->assertSame('currentUri', $parameter->getName(), "$class::$method()");
            $this->assertTrue($parameter->isOptional(), "$class::$method() \$currentUri must be optional");
        }
    }

    /**
     * Host comparison is skipped when the caller can't know the host (a console
     * request), rather than failing every absolute URL closed — the path
     * comparison is all that's available there.
     */
    public function testAbsoluteUrlStillMatchesWhenNoInternalHostsAreKnown(): void
    {
        $entry = $this->node(1, 'https://example.test/news/latest');

        (new MenuBuilderActiveResolver())->mark([$entry], 'news/latest');

        $this->assertTrue($entry->isActive);
    }

    // ----------------------------------------------------- cache boundary

    /**
     * Active state is per-request and must never end up on the cached tree.
     * MenuBuilderResolver marks the visibility-filtered copies produced by
     * MenuBuilderNode::withChildren(); this asserts the originals — the objects
     * MenuBuilderCacheService handed back — come out of a mark() pass untouched.
     */
    public function testMarkingNeverWritesActiveStateBackOntoTheCachedNodes(): void
    {
        $cachedChild = $this->node(2, '/products/shoes', level: 2);
        $cachedParent = $this->node(1, '/products', [$cachedChild]);

        $requestCopy = $cachedParent->withChildren([$cachedChild->withChildren([])]);

        $this->mark([$requestCopy], '/products/shoes');

        $this->assertTrue($requestCopy->children[0]->isActive);
        $this->assertTrue($requestCopy->isActiveAncestor);

        $this->assertFalse($cachedParent->isActive);
        $this->assertFalse($cachedParent->isActiveAncestor);
        $this->assertFalse($cachedChild->isActive);
        $this->assertFalse($cachedChild->isActiveAncestor);
    }
}

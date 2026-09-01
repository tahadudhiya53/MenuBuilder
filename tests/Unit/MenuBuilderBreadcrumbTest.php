<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;
use Tahadudhiya\MenuBuilder\services\MenuBuilderActiveResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderBreadcrumbService;

/**
 * Phase 19 — breadcrumbs.
 *
 * These tests deliberately do **not** stub active state: every trail is
 * built by running the real {@see MenuBuilderActiveResolver} over the tree
 * for a real URI first, exactly as MenuBuilderResolver::getTree() does, so a
 * breadcrumb can never pass here while disagreeing with the
 * `aria-current="page"` the same menu renders.
 *
 * No booted Craft app is needed: both services consume finished nodes.
 */
class MenuBuilderBreadcrumbTest extends TestCase
{
    /** The request host and the current site's base-URL host — see MenuBuilderResolver::internalHosts(). */
    private const SITE_HOSTS = ['example.test'];

    private function node(
        int $id,
        string $title,
        ?string $url,
        array $children = [],
        int $level = 1,
        string $type = MenuBuilderItem::TYPE_URL,
        bool $isLinkAvailable = true,
        bool $clickable = true,
    ): MenuBuilderNode {
        $node = new MenuBuilderNode(
            id: $id,
            handle: null,
            type: $type,
            title: $title,
            url: $url,
            isClickable: $clickable && $isLinkAvailable && $url !== null,
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

    /**
     * The phase's worked example, as an editor would build it:
     *
     *   Home
     *   Products
     *     Shoes
     *       Running Shoes
     *   Contact
     */
    private function shopMenu(): array
    {
        $running = $this->node(5, 'Running Shoes', '/products/shoes/running', level: 3);
        $shoes = $this->node(4, 'Shoes', '/products/shoes', [$running], level: 2);
        $products = $this->node(3, 'Products', '/products', [$shoes]);
        $home = $this->node(1, 'Home', '/');
        $contact = $this->node(2, 'Contact', '/contact');

        return [$home, $products, $contact];
    }

    /**
     * Resolves active state for `$currentUri` and returns the trail — the
     * two pipeline steps a front-end request runs, in order.
     *
     * @param MenuBuilderNode[] $topLevelNodes
     */
    private function trailFor(array $topLevelNodes, string $currentUri, array $hosts = self::SITE_HOSTS): array
    {
        (new MenuBuilderActiveResolver())->mark($topLevelNodes, $currentUri, $hosts);

        $tree = new MenuBuilderTree(new MenuBuilderGroup(['name' => 'Main', 'handle' => 'main']), $topLevelNodes);

        return array_map(
            fn(MenuBuilderNode $node) => $node->title,
            (new MenuBuilderBreadcrumbService())->trailForTree($tree)->crumbs
        );
    }

    // ------------------------------------------------------------- hierarchy

    public function testRootPageTrailIsThatOneItem(): void
    {
        $this->assertSame(['Home'], $this->trailFor($this->shopMenu(), '/'));
    }

    public function testChildPageTrailIsParentThenPage(): void
    {
        $this->assertSame(['Products', 'Shoes'], $this->trailFor($this->shopMenu(), '/products/shoes'));
    }

    public function testGrandchildPageTrailIsTheWholeChain(): void
    {
        $this->assertSame(
            ['Products', 'Shoes', 'Running Shoes'],
            $this->trailFor($this->shopMenu(), '/products/shoes/running')
        );
    }

    /** Root first, current page last — the order a breadcrumb is read in. */
    public function testTrailIsOrderedRootFirst(): void
    {
        $nodes = $this->shopMenu();
        (new MenuBuilderActiveResolver())->mark($nodes, '/products/shoes/running', self::SITE_HOSTS);

        $trail = (new MenuBuilderBreadcrumbService())->trailForTree(
            new MenuBuilderTree(new MenuBuilderGroup(), $nodes)
        );

        $this->assertCount(3, $trail);
        $this->assertSame('Products', $trail->root()->title);
        $this->assertSame('Running Shoes', $trail->current()->title);
        $this->assertSame(['Products', 'Shoes'], array_map(fn($n) => $n->title, $trail->ancestors()));
        $this->assertFalse($trail->isEmpty());
    }

    /** Hierarchy information: each crumb keeps its own depth, and the trail is iterable in Twig. */
    public function testCrumbsCarryTheirDepthAndAreIterable(): void
    {
        $nodes = $this->shopMenu();
        (new MenuBuilderActiveResolver())->mark($nodes, '/products/shoes/running', self::SITE_HOSTS);

        $trail = (new MenuBuilderBreadcrumbService())->trailForTree(
            new MenuBuilderTree(new MenuBuilderGroup(), $nodes)
        );

        $levels = [];
        foreach ($trail as $crumb) {
            $levels[] = $crumb->level;
        }

        $this->assertSame([1, 2, 3], $levels);
    }

    /** Active state: only the last crumb is the current page; the rest are its ancestors. */
    public function testOnlyTheLastCrumbIsTheCurrentPage(): void
    {
        $nodes = $this->shopMenu();
        (new MenuBuilderActiveResolver())->mark($nodes, '/products/shoes', self::SITE_HOSTS);

        $trail = (new MenuBuilderBreadcrumbService())->trailForTree(
            new MenuBuilderTree(new MenuBuilderGroup(), $nodes)
        );

        [$products, $shoes] = $trail->crumbs;

        $this->assertFalse($products->isActive);
        $this->assertTrue($products->isActiveAncestor);
        $this->assertTrue($shoes->isActive);
        $this->assertFalse($shoes->isActiveAncestor);
        $this->assertSame($shoes, $trail->current());
    }

    /**
     * The whole point of deriving the trail from the menu: the crumbs follow
     * the hierarchy the editor built, not the URL's segments. "Running Shoes"
     * sits directly under "Home" here, and its trail says so even though its
     * URL has three segments — a URL-splitting implementation would have
     * invented "Products" and "Shoes" crumbs that this menu never had.
     */
    public function testTrailFollowsTheMenuHierarchyAndNotTheUrlSegments(): void
    {
        $running = $this->node(5, 'Running Shoes', '/products/shoes/running', level: 2);
        $home = $this->node(1, 'Home', '/', [$running]);

        $this->assertSame(['Home', 'Running Shoes'], $this->trailFor([$home], '/products/shoes/running'));
    }

    /** A heading is a real crumb — it just isn't a link (MenuBuilderNode::$isClickable). */
    public function testNonClickableAncestorIsStillACrumb(): void
    {
        $shoes = $this->node(4, 'Shoes', '/products/shoes', level: 2);
        $products = $this->node(3, 'Products', null, [$shoes], type: MenuBuilderItem::TYPE_NONCLICKABLE, clickable: false);

        $nodes = [$products];
        (new MenuBuilderActiveResolver())->mark($nodes, '/products/shoes', self::SITE_HOSTS);

        $trail = (new MenuBuilderBreadcrumbService())->trailForTree(
            new MenuBuilderTree(new MenuBuilderGroup(), $nodes)
        );

        $this->assertSame(['Products', 'Shoes'], array_map(fn($n) => $n->title, $trail->crumbs));
        $this->assertFalse($trail->root()->isClickable);
        $this->assertNull($trail->root()->url);
    }

    /**
     * The same URL placed twice in one menu: the first in document order
     * wins, so the answer is stable across requests.
     */
    public function testDuplicateCurrentPageResolvesToTheFirstInDocumentOrder(): void
    {
        $utilityContact = $this->node(9, 'Contact us', '/contact', level: 2);
        $utility = $this->node(8, 'Utility', null, [$utilityContact], type: MenuBuilderItem::TYPE_NONCLICKABLE, clickable: false);
        $headerContact = $this->node(2, 'Contact', '/contact');

        $this->assertSame(['Contact'], $this->trailFor([$headerContact, $utility], '/contact'));
        $this->assertSame(['Utility', 'Contact us'], $this->trailFor([$utility, $headerContact], '/contact'));
    }

    // ----------------------------------------------------- nothing to report

    /**
     * No matching item: the page simply isn't in this menu. An empty trail,
     * never a trail guessed from `/legal/privacy`.
     */
    public function testNoMatchingItemYieldsAnEmptyTrail(): void
    {
        $this->assertSame([], $this->trailFor($this->shopMenu(), '/legal/privacy'));
    }

    /**
     * A missing item — an item deleted since, or one whose branch was never
     * added — is not in the resolved tree at all, so the page it used to
     * cover has no trail. (The resolver's fail-closed drop of a cached node
     * whose item is gone is covered by MenuBuilderCacheIntegrationTest; here
     * it is the *breadcrumb* consequence that matters.)
     */
    public function testPageWhoseItemIsMissingFromTheTreeYieldsAnEmptyTrail(): void
    {
        $nodes = $this->shopMenu();
        // "Shoes" and everything under it removed, as a deletion leaves the tree.
        $nodes[1]->children = [];

        $this->assertSame([], $this->trailFor($nodes, '/products/shoes'));
        $this->assertSame([], $this->trailFor($nodes, '/products/shoes/running'));
    }

    /**
     * A disabled item never reaches the resolved tree
     * (`MenuBuilderItemService::getTree(includeDisabled: false)`), and its
     * descendants go with it — so neither the disabled page nor a page
     * beneath it has a trail. Modelled here as what the resolver hands over:
     * the branch absent.
     */
    public function testDisabledItemAndItsDescendantsHaveNoTrail(): void
    {
        $home = $this->node(1, 'Home', '/');
        $contact = $this->node(2, 'Contact', '/contact');

        // "Products" (and therefore "Shoes"/"Running Shoes") disabled.
        $this->assertSame([], $this->trailFor([$home, $contact], '/products'));
        $this->assertSame([], $this->trailFor([$home, $contact], '/products/shoes/running'));
    }

    /**
     * An unpublished (or deleted, or disabled) linked element whose item is
     * set to keep the item resolves to an unavailable link. It cannot be the
     * current page — there is no page — so it yields no trail even when the
     * request path looks like the one it used to have.
     */
    public function testUnpublishedElementPageYieldsAnEmptyTrail(): void
    {
        $draft = $this->node(6, 'Draft product', '/products/draft', level: 2, type: MenuBuilderItem::TYPE_ENTRY, isLinkAvailable: false);
        $products = $this->node(3, 'Products', '/products', [$draft]);

        $this->assertSame([], $this->trailFor([$products], '/products/draft'));
    }

    /**
     * The same item as an *ancestor*, though, is still part of the path the
     * editor built: it stays in the trail as an unlinked crumb rather than
     * silently shortening the chain.
     */
    public function testUnpublishedElementAncestorStaysInTheTrailAsAnUnlinkedCrumb(): void
    {
        $child = $this->node(7, 'Care guide', '/products/draft/care', level: 3);
        $draft = $this->node(6, 'Draft product', null, [$child], level: 2, type: MenuBuilderItem::TYPE_ENTRY, isLinkAvailable: false);
        $products = $this->node(3, 'Products', '/products', [$draft]);

        $nodes = [$products];
        (new MenuBuilderActiveResolver())->mark($nodes, '/products/draft/care', self::SITE_HOSTS);

        $trail = (new MenuBuilderBreadcrumbService())->trailForTree(
            new MenuBuilderTree(new MenuBuilderGroup(), $nodes)
        );

        $this->assertSame(['Products', 'Draft product', 'Care guide'], array_map(fn($n) => $n->title, $trail->crumbs));
        $this->assertFalse($trail->crumbs[1]->isClickable);
    }

    /**
     * A tree resolved with active-state marking turned off — what the
     * control-panel preview asks for, because it doesn't simulate a page —
     * has no current page, and therefore no trail.
     */
    public function testUnmarkedTreeYieldsAnEmptyTrail(): void
    {
        $tree = new MenuBuilderTree(new MenuBuilderGroup(), $this->shopMenu());

        $this->assertTrue((new MenuBuilderBreadcrumbService())->trailForTree($tree)->isEmpty());
        $this->assertNull((new MenuBuilderBreadcrumbService())->trailForTree($tree)->current());
        $this->assertNull((new MenuBuilderBreadcrumbService())->trailForTree($tree)->root());
        $this->assertSame([], (new MenuBuilderBreadcrumbService())->trailForTree($tree)->ancestors());
    }

    public function testEmptyMenuYieldsAnEmptyTrail(): void
    {
        $this->assertSame([], $this->trailFor([], '/products/shoes'));
    }

    // ------------------------------------------------------------ custom URLs

    /** A root-relative custom URL is matched the way the menu matches it — query and trailing slash and all. */
    public function testCustomUrlItemTrailIgnoresTrailingSlashesAndQueryStrings(): void
    {
        $this->assertSame(['Products', 'Shoes'], $this->trailFor($this->shopMenu(), '/products/shoes/'));
        $this->assertSame(['Products', 'Shoes'], $this->trailFor($this->shopMenu(), 'products/shoes?page=2#top'));
    }

    /** An absolute custom URL on this site's own host is this site's page. */
    public function testAbsoluteCustomUrlOnThisHostYieldsATrail(): void
    {
        $shoes = $this->node(4, 'Shoes', 'https://example.test/products/shoes', level: 2);
        $products = $this->node(3, 'Products', '/products', [$shoes]);

        $this->assertSame(['Products', 'Shoes'], $this->trailFor([$products], '/products/shoes'));
    }

    /**
     * An external custom URL that happens to share a path with the request is
     * somebody else's page, so it is not the current page and there is no
     * trail — the breadcrumb inherits the active resolver's host rule rather
     * than restating it.
     */
    public function testExternalCustomUrlWithAMatchingPathYieldsNoTrail(): void
    {
        $shoes = $this->node(4, 'Shoes', 'https://shop.example.com/products/shoes', level: 2);
        $products = $this->node(3, 'Products', '/products', [$shoes]);

        $this->assertSame([], $this->trailFor([$products], '/products/shoes'));
    }

    /** A `mailto:` item is not a page, and a fragment-only anchor is not one either. */
    public function testNonNavigableItemsNeverStartATrail(): void
    {
        $mail = $this->node(10, 'Email us', 'mailto:hello@example.test');
        $anchor = $this->node(11, 'Top', '#top', type: MenuBuilderItem::TYPE_ANCHOR);

        $this->assertSame([], $this->trailFor([$mail, $anchor], 'hello@example.test'));
        $this->assertSame([], $this->trailFor([$mail, $anchor], '/#top'));
    }

    // ------------------------------------------------------------- multi-site

    /**
     * Sibling sites share path structure — `/contact` exists on English and
     * German. While the English site is being served, the German item is not
     * the current page, so it cannot start a German trail.
     */
    public function testSiblingSitePathDoesNotProduceATrail(): void
    {
        $german = $this->node(20, 'Kontakt', 'https://de.example.test/contact', level: 2);
        $international = $this->node(19, 'International', null, [$german], type: MenuBuilderItem::TYPE_NONCLICKABLE, clickable: false);

        $this->assertSame([], $this->trailFor([$international], 'https://example.test/contact'));
    }

    /** The same item, on the site it belongs to: the request host is that site's host, so the trail is built. */
    public function testCrossSiteItemHasATrailOnItsOwnSite(): void
    {
        $german = $this->node(20, 'Kontakt', 'https://de.example.test/contact', level: 2);
        $international = $this->node(19, 'International', null, [$german], type: MenuBuilderItem::TYPE_NONCLICKABLE, clickable: false);

        $this->assertSame(
            ['International', 'Kontakt'],
            $this->trailFor([$international], 'https://de.example.test/contact', ['de.example.test'])
        );
    }

    /**
     * A console request knows no host, so host comparison is skipped there
     * (MenuBuilderResolver::getTree passes no hosts) — the breadcrumb behaves
     * the same as the menu's own active state rather than differently.
     */
    public function testConsoleRequestWithoutHostsMatchesOnPathAlone(): void
    {
        $this->assertSame(['Products', 'Shoes'], $this->trailFor($this->shopMenu(), '/products/shoes', []));
    }

    // ------------------------------------------------------------ trail model

    public function testTrailKeepsItsGroup(): void
    {
        $group = new MenuBuilderGroup(['name' => 'Main', 'handle' => 'main']);
        $nodes = $this->shopMenu();
        (new MenuBuilderActiveResolver())->mark($nodes, '/contact', self::SITE_HOSTS);

        $trail = (new MenuBuilderBreadcrumbService())->trailForTree(new MenuBuilderTree($group, $nodes));

        $this->assertSame($group, $trail->group);
        $this->assertSame('main', $trail->group->handle);
    }
}

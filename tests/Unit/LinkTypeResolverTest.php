<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\linktypes\AnchorLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\DynamicLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\ElementLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\LinkTypeResolverInterface;
use Tahadudhiya\MenuBuilder\linktypes\NonClickableLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\UrlLinkResolver;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\services\MenuBuilderDynamicNavigationService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderLinkResolver;

/**
 * Link resolution for every item type: the registry that maps a type to its
 * resolver, and each resolver's own decisions.
 *
 * An unregistered type resolves to ResolvedLink::unavailable(), which the
 * default `hide` fallback then drops from every rendered menu — a silent
 * disappearance rather than a loud failure — so the registry is checked in
 * both directions. ElementLinkResolver's element query needs a booted Craft
 * app and is covered by manual testing; its two pure decisions (is this
 * element publicly available on this site, and what happens when it is not)
 * are covered here.
 */
class LinkTypeResolverTest extends TestCase
{
    // ---------------------------------------------------------------------
    // The registry: every declared type maps to exactly one resolver
    // ---------------------------------------------------------------------

    /**
     * @return array<string,LinkTypeResolverInterface>
     */
    private function resolvers(): array
    {
        $service = new MenuBuilderLinkResolver();
        $method = new \ReflectionMethod($service, 'getResolvers');

        return $method->invoke($service);
    }

    public function testEveryDeclaredLinkTypeHasAResolver(): void
    {
        $resolvers = $this->resolvers();

        foreach (MenuBuilderItem::TYPES as $type) {
            $this->assertArrayHasKey($type, $resolvers, "No link type resolver registered for \"$type\".");
            $this->assertInstanceOf(LinkTypeResolverInterface::class, $resolvers[$type]);
        }
    }

    /** No resolver is registered for a type that isn't declared. */
    public function testNoResolverIsRegisteredForAnUnknownType(): void
    {
        foreach (array_keys($this->resolvers()) as $type) {
            $this->assertContains($type, MenuBuilderItem::TYPES, "Resolver registered for unknown type \"$type\".");
        }
    }

    // ---------------------------------------------------------------------
    // The resolvers themselves
    // ---------------------------------------------------------------------

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

    /**
     * A `dynamic` item is a container for its synthesized children, not a
     * link — but it must resolve as *available*, since MenuBuilderResolver
     * drops any unavailable item whose fallbackBehavior is `hide` (the
     * default, and the only value the type-scoped editor fields can leave a
     * dynamic item with). Before this type had a resolver at all it fell
     * through to unavailable() and every dynamic item — plus every child it
     * would have generated — vanished from the rendered tree.
     */
    public function testDynamicLinkResolverIsAvailableWithoutAUrl(): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_HIDE;

        $link = (new DynamicLinkResolver())->resolve($item);

        $this->assertTrue($link->isAvailable);
        $this->assertNull($link->url);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function acceptableCustomUrlProvider(): array
    {
        return [
            'root-relative path' => ['/about/team'],
            'absolute http' => ['http://example.test/page'],
            'absolute https' => ['https://example.test/page'],
            'query parameters' => ['https://example.test/search?q=menu&page=2'],
            'fragment only' => ['#section-3'],
            'path with fragment' => ['/about#team'],
            'query and fragment' => ['https://example.test/a?b=c#d'],
            'mailto' => ['mailto:hello@example.test'],
            'tel' => ['tel:+15550100'],
        ];
    }

    /**
     * @dataProvider acceptableCustomUrlProvider
     */
    public function testUrlLinkResolverPassesThroughAcceptableUrls(string $url): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = $url;

        $link = (new UrlLinkResolver())->resolve($item);

        $this->assertTrue($link->isAvailable, "Expected available: $url");
        $this->assertSame($url, $link->url);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function unsafeOrMalformedCustomUrlProvider(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'javascript with authority' => ['javascript://example.test%0Aalert(1)'],
            'data scheme' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript scheme' => ['vbscript:msgbox(1)'],
            'bare fragment marker' => ['#'],
            'malformed scheme-relative garbage' => ['http://'],
            'not a url at all' => ['not a url'],
            'whitespace only' => ['   '],
        ];
    }

    /**
     * Validation rejects these on save; this covers the stored value that
     * never went through validation (import, direct DB edit, a row written
     * before the scheme denylist existed) — the resolver must not hand an
     * executable or malformed value to an `href`.
     *
     * @dataProvider unsafeOrMalformedCustomUrlProvider
     */
    public function testUrlLinkResolverRefusesUnsafeOrMalformedStoredUrls(string $url): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = $url;

        $link = (new UrlLinkResolver())->resolve($item);

        $this->assertFalse($link->isAvailable, "Expected unavailable: $url");
        $this->assertNull($link->url);
    }

    /**
     * The editor's "Anchor handle" field posts `customUrl` and is documented
     * as "leave blank to reuse the Handle field in Advanced", so customUrl
     * wins — `handle` also doubles as the CSS-targeting handle, and
     * preferring it meant an item with both silently linked to the wrong
     * fragment.
     */
    public function testAnchorLinkResolverPrefersTheAnchorFieldOverTheCssHandle(): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_ANCHOR;
        $item->handle = 'css-hook';
        $item->customUrl = 'pricing';

        $this->assertSame('#pricing', (new AnchorLinkResolver())->resolve($item)->url);
    }

    public function testAnchorLinkResolverFallsBackToTheHandle(): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_ANCHOR;
        $item->handle = 'section-2';
        $item->customUrl = '   ';

        $this->assertSame('#section-2', (new AnchorLinkResolver())->resolve($item)->url);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function malformedAnchorProvider(): array
    {
        return [
            'contains a space' => ['section 2'],
            'double quote' => ['section"2'],
            'single quote' => ["section'2"],
            'angle brackets' => ['<script>'],
            'tab' => ["section\t2"],
            'newline' => ["section\n2"],
            'bare hash' => ['#'],
        ];
    }

    /**
     * @dataProvider malformedAnchorProvider
     */
    public function testAnchorLinkResolverRefusesMalformedFragments(string $anchor): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_ANCHOR;
        $item->customUrl = $anchor;

        $link = (new AnchorLinkResolver())->resolve($item);

        $this->assertFalse($link->isAvailable, "Expected unavailable: $anchor");
        $this->assertNull($link->url);
    }

    public function testAnchorLinkResolverNormalizesALeadingHash(): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_ANCHOR;
        $item->customUrl = '  #already-hashed  ';

        $this->assertSame('#already-hashed', (new AnchorLinkResolver())->resolve($item)->url);
    }

    /**
     * Availability comes from the element's *status*, which accounts for
     * per-site enabled state — the previous `enabled`-flag check let a
     * category or asset disabled for the site being rendered still produce
     * a link there.
     *
     * @return array<string,array{class-string,string|null,bool}>
     */
    public static function elementAvailabilityProvider(): array
    {
        return [
            'live entry' => [Entry::class, Entry::STATUS_LIVE, true],
            'pending entry' => [Entry::class, Entry::STATUS_PENDING, false],
            'expired entry' => [Entry::class, Entry::STATUS_EXPIRED, false],
            'disabled entry' => [Entry::class, Entry::STATUS_DISABLED, false],
            'enabled category' => [Category::class, Category::STATUS_ENABLED, true],
            'disabled category' => [Category::class, Category::STATUS_DISABLED, false],
            'enabled asset' => [Asset::class, Asset::STATUS_ENABLED, true],
            'disabled asset' => [Asset::class, Asset::STATUS_DISABLED, false],
            'no status at all' => [Entry::class, null, false],
        ];
    }

    /**
     * @param class-string $elementClass
     * @dataProvider elementAvailabilityProvider
     */
    public function testElementAvailabilityIsDecidedByStatus(string $elementClass, ?string $status, bool $expected): void
    {
        $this->assertSame($expected, ElementLinkResolver::isPubliclyAvailable($elementClass, $status));
    }

    public function testElementFallbackHideResolvesUnavailable(): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_ENTRY;
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_HIDE;

        $link = ElementLinkResolver::fallbackFor($item);

        $this->assertFalse($link->isAvailable);
        $this->assertNull($link->url);
    }

    public function testElementFallbackDisableLinkKeepsTheItemWithoutALink(): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_ENTRY;
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_DISABLE_LINK;

        $link = ElementLinkResolver::fallbackFor($item);

        $this->assertTrue($link->isAvailable);
        $this->assertNull($link->url);
    }

    public function testElementFallbackUrlIsUsedWhenSet(): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_ENTRY;
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_FALLBACK_URL;
        $item->fallbackUrl = '/archive';

        $this->assertSame('/archive', ElementLinkResolver::fallbackFor($item)->url);
    }

    /**
     * @return array<string,array{string|null}>
     */
    public static function unusableFallbackUrlProvider(): array
    {
        return [
            'missing' => [null],
            'javascript scheme' => ['javascript://example.test%0Aalert(1)'],
            'malformed' => ['not a url'],
        ];
    }

    /**
     * @dataProvider unusableFallbackUrlProvider
     */
    public function testAnUnusableFallbackUrlResolvesUnavailableRatherThanBeingEmitted(?string $url): void
    {
        $item = new MenuBuilderItem();
        $item->type = MenuBuilderItem::TYPE_ENTRY;
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_FALLBACK_URL;
        $item->fallbackUrl = $url;

        $link = ElementLinkResolver::fallbackFor($item);

        $this->assertFalse($link->isAvailable);
        $this->assertNull($link->url);
    }

    // =====================================================================
    // Dynamic source configuration
    // =====================================================================

    public function testAValidConfigPassesThrough(): void
    {
        $config = MenuBuilderDynamicNavigationService::normalizeConfig([
            'sourceType' => 'entries',
            'sourceId' => 4,
            'limit' => 12,
            'orderBy' => 'title asc',
        ]);

        $this->assertSame([
            'sourceType' => 'entries',
            'sourceId' => 4,
            'limit' => 12,
            'orderBy' => 'title asc',
        ], $config);
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function unusableConfigProvider(): array
    {
        return [
            'empty' => [[]],
            'unknown source type' => [['sourceType' => 'users', 'sourceId' => 1]],
            'missing source id' => [['sourceType' => 'entries']],
            'zero source id' => [['sourceType' => 'entries', 'sourceId' => 0]],
            'negative source id' => [['sourceType' => 'entries', 'sourceId' => -3]],
            'non-numeric source id' => [['sourceType' => 'entries', 'sourceId' => 'abc']],
            'boolean source id' => [['sourceType' => 'entries', 'sourceId' => true]],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @dataProvider unusableConfigProvider
     */
    public function testAnUnusableConfigNormalizesToNull(array $config): void
    {
        $this->assertNull(MenuBuilderDynamicNavigationService::normalizeConfig($config));
    }

    /** The stored limit is never trusted — the server cap wins, in both directions. */
    public function testLimitIsAlwaysClamped(): void
    {
        $base = ['sourceType' => 'categories', 'sourceId' => 2];
        $max = MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT;

        $this->assertSame($max, MenuBuilderDynamicNavigationService::normalizeConfig($base + ['limit' => 5000])['limit']);
        $this->assertSame($max, MenuBuilderDynamicNavigationService::normalizeConfig($base)['limit']);
        $this->assertSame(1, MenuBuilderDynamicNavigationService::normalizeConfig($base + ['limit' => 0])['limit']);
        $this->assertSame(1, MenuBuilderDynamicNavigationService::normalizeConfig($base + ['limit' => -10])['limit']);
        $this->assertSame(3, MenuBuilderDynamicNavigationService::normalizeConfig($base + ['limit' => '3'])['limit']);
    }

    /** `orderBy` reaches a query builder, so anything off the whitelist is discarded, not passed along. */
    public function testOrderByFallsBackToTheDefaultWhenNotWhitelisted(): void
    {
        $base = ['sourceType' => 'assets', 'sourceId' => 7];
        $default = MenuBuilderItem::DYNAMIC_SOURCE_ORDER_BY[0];

        foreach ([null, '', 'title asc; drop table x', 'RAND()', 'dateUpdated desc', ['title asc']] as $bad) {
            $config = MenuBuilderDynamicNavigationService::normalizeConfig($base + ['orderBy' => $bad]);
            $this->assertSame($default, $config['orderBy']);
        }

        foreach (MenuBuilderItem::DYNAMIC_SOURCE_ORDER_BY as $good) {
            $config = MenuBuilderDynamicNavigationService::normalizeConfig($base + ['orderBy' => $good]);
            $this->assertSame($good, $config['orderBy']);
        }
    }

    /** Every source type the item model declares must be buildable into a query. */
    public function testEveryDeclaredSourceTypeNormalizes(): void
    {
        foreach (MenuBuilderItem::DYNAMIC_SOURCE_TYPES as $sourceType) {
            $config = MenuBuilderDynamicNavigationService::normalizeConfig(['sourceType' => $sourceType, 'sourceId' => 1]);
            $this->assertNotNull($config, "Source type \"$sourceType\" did not normalize.");
            $this->assertSame($sourceType, $config['sourceType']);
        }
    }
}

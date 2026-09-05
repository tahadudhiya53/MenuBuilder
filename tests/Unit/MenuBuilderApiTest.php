<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderApiHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderApiConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * The REST API's decidable half: whether the endpoint exists at all, what it
 * refuses, what it returns for a given tree, and the arithmetic behind its
 * caching and rate-limiting headers.
 *
 * No booted Craft application. Whether a real HTTP request reaches the
 * controller, and what a real schema lets it read, is
 * {@see \Tahadudhiya\MenuBuilder\Tests\Integration\MenuBuilderApiTest}'s job.
 */
class MenuBuilderApiTest extends TestCase
{
    // ---------------------------------------------------------------------
    // The master switch — the difference between "no API" and "an API"
    // ---------------------------------------------------------------------

    public function testApiIsOffWithNoConfigAtAll(): void
    {
        $this->assertFalse(MenuBuilderApiConfig::fromArray([])->enabled);
        $this->assertFalse(MenuBuilderApiConfig::fromArray(null)->enabled);
        $this->assertFalse(MenuBuilderApiConfig::fromArray('nonsense')->enabled);
        $this->assertFalse(MenuBuilderApiConfig::fromArray(['api' => 'yes'])->enabled);
        $this->assertFalse(MenuBuilderApiConfig::fromArray(['api' => []])->enabled);
    }

    /**
     * Publishing an endpoint must take a literal `true`. A truthy string in a
     * config file is as likely to be a mistake as an intention, and the
     * mistake is the one with consequences.
     *
     * @dataProvider truthyButNotTrue
     */
    public function testApiIsOffForAnythingButLiteralTrue(mixed $value): void
    {
        $this->assertFalse(MenuBuilderApiConfig::fromArray(['api' => ['enabled' => $value]])->enabled);
    }

    public static function truthyButNotTrue(): array
    {
        return [[1], ['1'], ['true'], ['yes'], [1.0], [[1]], [null], [false], ['false']];
    }

    public function testApiIsOnForLiteralTrue(): void
    {
        $config = MenuBuilderApiConfig::fromArray(['api' => ['enabled' => true]]);

        $this->assertTrue($config->enabled);
        $this->assertSame('api/menu-builder', $config->basePath);
        $this->assertSame('api/menu-builder/v1', $config->routePrefix());
        $this->assertTrue($config->allowPublicSchema);
        $this->assertSame(60, $config->rateLimit);
        $this->assertSame(0, $config->cacheDuration);
        $this->assertSame([], $config->allowedOrigins);
    }

    // ---------------------------------------------------------------------
    // Config normalization — a config file is hand-edited and deployed
    // ---------------------------------------------------------------------

    /**
     * @dataProvider basePaths
     */
    public function testBasePathNormalization(mixed $given, string $expected): void
    {
        $this->assertSame($expected, self::enabled(['basePath' => $given])->basePath);
    }

    public static function basePaths(): array
    {
        return [
            'plain' => ['menus', 'menus'],
            'nested' => ['api/v2/nav', 'api/v2/nav'],
            'leading and trailing slashes trimmed' => ['/api/nav/', 'api/nav'],
            'whitespace trimmed' => ['  api/nav  ', 'api/nav'],
            'dot inside a segment is fine' => ['api.json/nav', 'api.json/nav'],
            'empty' => ['', 'api/menu-builder'],
            'only slashes' => ['///', 'api/menu-builder'],
            'empty segment' => ['api//nav', 'api/menu-builder'],
            'dot segment' => ['api/./nav', 'api/menu-builder'],
            'parent segment' => ['api/../nav', 'api/menu-builder'],
            'segment starting with a dot' => ['.hidden', 'api/menu-builder'],
            'query characters' => ['api?nav', 'api/menu-builder'],
            'a regex the route would inherit' => ['api/<x:.*>', 'api/menu-builder'],
            'space' => ['api nav', 'api/menu-builder'],
            'not a string' => [42, 'api/menu-builder'],
            'null' => [null, 'api/menu-builder'],
        ];
    }

    public function testAllowPublicSchemaIsOnlyTurnedOffByLiteralFalse(): void
    {
        $this->assertTrue(self::enabled([])->allowPublicSchema);
        $this->assertTrue(self::enabled(['allowPublicSchema' => 0])->allowPublicSchema);
        $this->assertFalse(self::enabled(['allowPublicSchema' => false])->allowPublicSchema);
    }

    /**
     * @dataProvider numbers
     */
    public function testNonNegativeIntegerSettings(mixed $given, int $expectedRateLimit, int $expectedDuration): void
    {
        $config = self::enabled(['rateLimit' => $given, 'cacheDuration' => $given]);

        $this->assertSame($expectedRateLimit, $config->rateLimit);
        $this->assertSame($expectedDuration, $config->cacheDuration);
    }

    public static function numbers(): array
    {
        return [
            'int' => [120, 120, 120],
            'zero disables' => [0, 0, 0],
            'digit string' => ['30', 30, 30],
            'negative falls back' => [-1, 60, 0],
            'float' => [1.5, 60, 0],
            'non-numeric string' => ['lots', 60, 0],
            'null' => [null, 60, 0],
            'true' => [true, 60, 0],
        ];
    }

    public function testOriginAllowlistKeepsOnlyRealOrigins(): void
    {
        $config = self::enabled([
            'allowedOrigins' => [
                'https://www.example.com',
                'http://localhost:3000/',
                'https://www.example.com',
                'https://example.com/app',
                'example.com',
                'ftp://example.com',
                'javascript:alert(1)',
                42,
                ['https://example.com'],
            ],
        ]);

        $this->assertSame(['https://www.example.com', 'http://localhost:3000'], $config->allowedOrigins);
    }

    public function testOneWildcardCollapsesTheAllowlist(): void
    {
        $config = self::enabled(['allowedOrigins' => ['https://a.example', '*', 'https://b.example']]);

        $this->assertSame(['*'], $config->allowedOrigins);
    }

    public function testNoCorsHeadersWithoutAnAllowlist(): void
    {
        $config = self::enabled([]);

        $this->assertFalse($config->isOriginAllowed('https://www.example.com'));
        $this->assertNull($config->allowOriginHeader('https://www.example.com'));
        $this->assertNull($config->allowOriginHeader(null));
    }

    /**
     * The classic allowlist bug: `evil-example.com` and `example.com.evil.net`
     * must not be admitted for `example.com`.
     */
    public function testOriginMatchingIsExact(): void
    {
        $config = self::enabled(['allowedOrigins' => ['https://example.com']]);

        $this->assertSame('https://example.com', $config->allowOriginHeader('https://example.com'));
        $this->assertNull($config->allowOriginHeader('https://evil-example.com'));
        $this->assertNull($config->allowOriginHeader('https://example.com.evil.net'));
        $this->assertNull($config->allowOriginHeader('http://example.com'));
        $this->assertNull($config->allowOriginHeader('https://example.com:8080'));
    }

    /** A configured wildcard is echoed as `*`, never as the caller's own origin. */
    public function testWildcardIsEchoedAsAWildcard(): void
    {
        $config = self::enabled(['allowedOrigins' => ['*']]);

        $this->assertSame('*', $config->allowOriginHeader('https://anything.example'));
        $this->assertNull($config->allowOriginHeader(null));
    }

    // ---------------------------------------------------------------------
    // Malformed input
    // ---------------------------------------------------------------------

    public function testNoParametersIsValid(): void
    {
        $this->assertNull(MenuBuilderApiHelper::invalidParam([]));
        $this->assertSame([], MenuBuilderApiHelper::arguments([]));
    }

    public function testValidParametersAreAccepted(): void
    {
        $params = [
            'site' => 'de',
            'siteId' => '2',
            'currentUri' => 'about/team',
            'viewport' => 'mobile',
        ];

        $this->assertNull(MenuBuilderApiHelper::invalidParam($params));
        $this->assertSame([
            'site' => 'de',
            'siteId' => 2,
            'currentUri' => 'about/team',
            'viewport' => 'mobile',
        ], MenuBuilderApiHelper::arguments($params));
    }

    /**
     * @dataProvider malformedParams
     */
    public function testMalformedParametersAreNamed(array $params, string $expected): void
    {
        $this->assertSame($expected, MenuBuilderApiHelper::invalidParam($params));
    }

    public static function malformedParams(): array
    {
        return [
            'site is not a handle' => [['site' => 'de-DE'], 'site'],
            'site is empty' => [['site' => ''], 'site'],
            'site is an array' => [['site' => ['de']], 'site'],
            'siteId is not a number' => [['siteId' => 'two'], 'siteId'],
            'siteId is zero' => [['siteId' => '0'], 'siteId'],
            'siteId is negative' => [['siteId' => '-1'], 'siteId'],
            'currentUri is empty' => [['currentUri' => ''], 'currentUri'],
            'currentUri is an array' => [['currentUri' => []], 'currentUri'],
            'viewport is a typo' => [['viewport' => 'mobil'], 'viewport'],
            'viewport is empty' => [['viewport' => ''], 'viewport'],
            'the first offender is named' => [['site' => '!', 'viewport' => 'mobil'], 'site'],
        ];
    }

    public function testAnOverlongCurrentUriIsRejected(): void
    {
        $this->assertSame('currentUri', MenuBuilderApiHelper::invalidParam([
            'currentUri' => str_repeat('a', 2049),
        ]));
    }

    /**
     * Unrecognized parameters are ignored rather than rejected — but they must
     * not reach the resolve pipeline, which distinguishes "named nothing
     * usable" from "named nothing" by `isset()`.
     */
    public function testUnknownParametersAreIgnoredAndNeverForwarded(): void
    {
        $params = ['site' => 'de', 'depth' => '2', 'callback' => 'alert', 'admin' => '1'];

        $this->assertNull(MenuBuilderApiHelper::invalidParam($params));
        $this->assertSame(['site' => 'de'], MenuBuilderApiHelper::arguments($params));
    }

    // ---------------------------------------------------------------------
    // The response shape
    // ---------------------------------------------------------------------

    public function testEnvelopeCarriesTheVersionAndTheAnsweringSite(): void
    {
        $envelope = MenuBuilderApiHelper::envelope(
            ['handle' => 'main'],
            ['id' => 2, 'handle' => 'de', 'language' => 'de'],
            ['currentUri' => 'about', 'viewport' => 'mobile'],
        );

        $this->assertSame(MenuBuilderApiConfig::RELEASE, $envelope['meta']['apiVersion']);
        $this->assertSame(['id' => 2, 'handle' => 'de', 'language' => 'de'], $envelope['meta']['site']);
        $this->assertSame('about', $envelope['meta']['currentUri']);
        $this->assertSame('mobile', $envelope['meta']['viewport']);
        $this->assertSame(['handle' => 'main'], $envelope['data']);
    }

    public function testEnvelopeReportsUnaskedForArgumentsAsNull(): void
    {
        $envelope = MenuBuilderApiHelper::envelope([], ['id' => 1, 'handle' => 'default', 'language' => 'en-US'], []);

        $this->assertNull($envelope['meta']['currentUri']);
        $this->assertNull($envelope['meta']['viewport']);
    }

    public function testErrorEnvelope(): void
    {
        $this->assertSame([
            'error' => [
                'status' => 404,
                'code' => 'not_found',
                'message' => 'No such navigation.',
            ],
        ], MenuBuilderApiHelper::error(404, MenuBuilderApiHelper::ERROR_NOT_FOUND, 'No such navigation.'));
    }

    public function testTreeSerialization(): void
    {
        $group = new MenuBuilderGroup([
            'id' => 7,
            'name' => 'Main Navigation',
            'handle' => 'main',
            'uid' => 'menu-uid',
            'description' => 'The top one',
            'cssClass' => 'site-nav',
            'maxDepth' => 3,
            'htmlAttributes' => ['data-nav' => 'main', 'onclick' => 'steal()'],
        ]);

        $tree = new MenuBuilderTree($group, [$this->node(), $this->node(title: 'About', url: '/about')]);
        $serialized = MenuBuilderApiHelper::serializeTree($tree);

        $this->assertSame('main', $serialized['handle']);
        $this->assertSame('Main Navigation', $serialized['name']);
        $this->assertSame('menu-uid', $serialized['uid']);
        $this->assertSame('The top one', $serialized['description']);
        $this->assertSame('site-nav', $serialized['cssClass']);
        $this->assertSame(3, $serialized['maxDepth']);
        $this->assertSame(2, $serialized['itemCount']);
        $this->assertCount(2, $serialized['items']);

        // The group's own fail-closed attribute read, not the raw bag.
        $this->assertSame(['data-nav' => 'main'], (array)$serialized['htmlAttributes']);
    }

    /**
     * A menu's row ID and its site-restriction list are facts about the
     * install, not about the navigation a visitor is being handed.
     */
    public function testTreeSerializationExposesNoInstallStructure(): void
    {
        $group = new MenuBuilderGroup([
            'id' => 7,
            'name' => 'Main',
            'handle' => 'main',
            'siteIds' => [1, 2],
            'settings' => ['secret' => 'value'],
        ]);

        $serialized = MenuBuilderApiHelper::serializeTree(new MenuBuilderTree($group, []));

        $this->assertArrayNotHasKey('id', $serialized);
        $this->assertArrayNotHasKey('siteIds', $serialized);
        $this->assertArrayNotHasKey('settings', $serialized);
        $this->assertArrayNotHasKey('enabled', $serialized);
        $this->assertSame([], $serialized['items']);
    }

    public function testNodeSerialization(): void
    {
        $node = $this->node(
            title: 'About',
            url: '/about',
            handle: 'about',
            htmlAttributes: ['data-track' => 'nav', 'href' => 'javascript:alert(1)'],
        );

        $serialized = MenuBuilderApiHelper::serializeNode($node);

        $this->assertSame('about', $serialized['handle']);
        $this->assertSame(MenuBuilderItem::TYPE_URL, $serialized['type']);
        $this->assertSame(1, $serialized['level']);
        $this->assertFalse($serialized['isDynamic']);
        $this->assertSame('About', $serialized['title']);
        $this->assertSame('/about', $serialized['url']);
        $this->assertTrue($serialized['isClickable']);
        $this->assertTrue($serialized['isLinkAvailable']);
        $this->assertSame('_self', $serialized['target']);
        $this->assertFalse($serialized['opensInNewTab']);
        $this->assertFalse($serialized['isActive']);
        $this->assertFalse($serialized['isActiveAncestor']);
        $this->assertFalse($serialized['hasChildren']);
        $this->assertSame([], $serialized['children']);

        // `href` is reserved and never comes from the bag — the node's own
        // accessor decides, exactly as it does for a rendered menu.
        $this->assertSame(['data-track' => 'nav'], (array)$serialized['htmlAttributes']);
    }

    /**
     * A node's `id` is a `menubuilder_items` primary key on an authored item
     * and a Craft *element* ID on a dynamic item's synthesized child. It is
     * never exposed.
     */
    public function testNodeSerializationNeverExposesTheRowId(): void
    {
        $this->assertArrayNotHasKey('id', MenuBuilderApiHelper::serializeNode($this->node()));
    }

    public function testAbsentPresentationGroupsAreNull(): void
    {
        $serialized = MenuBuilderApiHelper::serializeNode($this->node());

        $this->assertNull($serialized['icon']);
        $this->assertNull($serialized['badge']);
        $this->assertNull($serialized['megaMenu']);
        $this->assertNull($serialized['megaMenuColumn']);
    }

    public function testPresentationGroupsAreGrouped(): void
    {
        $node = $this->node(
            icon: 'fa fa-home',
            badge: 'New',
            badgeStyle: 'success',
            megaMenu: new MenuBuilderMegaMenuConfig(columns: 4),
            megaMenuColumn: 2,
        );

        $serialized = MenuBuilderApiHelper::serializeNode($node);

        $this->assertSame('class', $serialized['icon']['type']);
        $this->assertSame('fa fa-home', $serialized['icon']['class']);
        $this->assertNull($serialized['icon']['assetId']);

        $this->assertSame('New', $serialized['badge']['text']);
        $this->assertSame('success', $serialized['badge']['style']);
        $this->assertNotSame('', $serialized['badge']['class']);

        $this->assertSame(['columns' => 4], $serialized['megaMenu']);
        $this->assertSame(2, $serialized['megaMenuColumn']);
    }

    public function testMobileFactsAreGrouped(): void
    {
        $node = $this->node(mobile: [
            'visibility' => 'mobileOnly',
            'order' => 3,
            'collapsible' => true,
            'megaMenu' => 'stack',
        ])->withChildren([$this->node(title: 'Child', url: '/child')]);

        $mobile = MenuBuilderApiHelper::serializeNode($node)['mobile'];

        $this->assertSame('mobileOnly', $mobile['visibility']);
        $this->assertSame(3, $mobile['order']);
        $this->assertTrue($mobile['isCollapsible']);
        $this->assertSame('stack', $mobile['megaMenuBehavior']);
        $this->assertSame('mobile', $mobile['viewportAttribute']);
    }

    public function testChildrenAreSerializedRecursively(): void
    {
        $child = $this->node(title: 'Team', url: '/about/team');
        $grandchild = $this->node(title: 'Ada', url: '/about/team/ada');

        $parent = $this->node(title: 'About', url: '/about')
            ->withChildren([$child->withChildren([$grandchild])]);

        $serialized = MenuBuilderApiHelper::serializeNode($parent);

        $this->assertTrue($serialized['hasChildren']);
        $this->assertSame('Team', $serialized['children'][0]['title']);
        $this->assertSame('Ada', $serialized['children'][0]['children'][0]['title']);
        $this->assertSame([], $serialized['children'][0]['children'][0]['children']);
    }

    /**
     * A node with no content element serializes an empty field bag without
     * reaching for the plugin — which is what keeps this suite runnable
     * without a booted Craft, and what a separator or a fresh item really
     * is.
     */
    public function testANodeWithoutContentSerializesNoCustomFields(): void
    {
        $serialized = MenuBuilderApiHelper::serializeNode($this->node());

        $this->assertSame([], (array)$serialized['customFields']);
    }

    /**
     * The bag's JSON *type* must not change with its contents — an empty bag
     * that encodes as `[]` while a populated one encodes as `{}` is what
     * breaks a typed consumer.
     */
    public function testAnEmptyBagEncodesAsAnObject(): void
    {
        $serialized = MenuBuilderApiHelper::serializeNode($this->node());

        $this->assertSame('{}', json_encode($serialized['customFields']));
        $this->assertSame('{}', json_encode($serialized['htmlAttributes']));
    }

    // ---------------------------------------------------------------------
    // Caching
    // ---------------------------------------------------------------------

    public function testEtagIsStableAndBodySpecific(): void
    {
        $etag = MenuBuilderApiHelper::etag('{"a":1}');

        $this->assertSame($etag, MenuBuilderApiHelper::etag('{"a":1}'));
        $this->assertNotSame($etag, MenuBuilderApiHelper::etag('{"a":2}'));
        $this->assertMatchesRegularExpression('/^"[0-9a-f]+"$/', $etag);
    }

    /**
     * @dataProvider etagHeaders
     */
    public function testIfNoneMatch(?string $header, bool $expected): void
    {
        $this->assertSame($expected, MenuBuilderApiHelper::etagMatches($header, '"abc"'));
    }

    public static function etagHeaders(): array
    {
        return [
            'absent' => [null, false],
            'blank' => ['   ', false],
            'exact' => ['"abc"', true],
            'weak' => ['W/"abc"', true],
            'in a list' => ['"xyz", "abc"', true],
            'wildcard' => ['*', true],
            'different tag' => ['"xyz"', false],
            'unquoted' => ['abc', false],
            'a prefix is not a match' => ['"ab"', false],
        ];
    }

    public function testCacheControlSaysNoStoreWhenNoDurationWasConfigured(): void
    {
        $this->assertSame('no-store', MenuBuilderApiHelper::cacheControl(0, false));
        $this->assertSame('no-store', MenuBuilderApiHelper::cacheControl(0, true));
    }

    /**
     * A token's schema can name menus the public schema cannot, so a
     * token-authenticated response must never be storable by a shared cache.
     */
    public function testAnAuthenticatedResponseIsPrivate(): void
    {
        $this->assertSame('public, max-age=300', MenuBuilderApiHelper::cacheControl(300, false));
        $this->assertSame('private, max-age=300', MenuBuilderApiHelper::cacheControl(300, true));
    }

    // ---------------------------------------------------------------------
    // Rate limiting
    // ---------------------------------------------------------------------

    public function testRateLimitWindowsAreFixedMinutes(): void
    {
        $this->assertSame(
            MenuBuilderApiHelper::rateLimitWindow(1_700_000_000),
            MenuBuilderApiHelper::rateLimitWindow(1_700_000_059 - (1_700_000_000 % 60)),
        );
        $this->assertNotSame(
            MenuBuilderApiHelper::rateLimitWindow(1_700_000_000),
            MenuBuilderApiHelper::rateLimitWindow(1_700_000_060),
        );
    }

    public function testRateLimitResetIsTheRemainderOfTheWindow(): void
    {
        $this->assertSame(60, MenuBuilderApiHelper::rateLimitResetsIn(1_700_000_040));
        $this->assertSame(59, MenuBuilderApiHelper::rateLimitResetsIn(1_700_000_041));
        $this->assertSame(1, MenuBuilderApiHelper::rateLimitResetsIn(1_700_000_099));
    }

    /**
     * An access token must never appear in a cache key, and an IP address has
     * no business being stored in the clear to count requests.
     */
    public function testRateLimitKeyLeaksNeitherTokenNorAddress(): void
    {
        $key = MenuBuilderApiHelper::rateLimitKey('token-uid-1234', '203.0.113.9', 42);

        $this->assertStringNotContainsString('token-uid-1234', $key);
        $this->assertStringNotContainsString('203.0.113.9', $key);
        $this->assertStringStartsWith('menu-builder:api:rate:', $key);
        $this->assertStringEndsWith(':42', $key);
    }

    public function testRateLimitKeySeparatesCallersWindowsAndTokens(): void
    {
        $anonymous = MenuBuilderApiHelper::rateLimitKey(null, '203.0.113.9', 42);

        $this->assertNotSame($anonymous, MenuBuilderApiHelper::rateLimitKey('token', '203.0.113.9', 42));
        $this->assertNotSame($anonymous, MenuBuilderApiHelper::rateLimitKey(null, '203.0.113.10', 42));
        $this->assertNotSame($anonymous, MenuBuilderApiHelper::rateLimitKey(null, '203.0.113.9', 43));
        $this->assertSame($anonymous, MenuBuilderApiHelper::rateLimitKey(null, '203.0.113.9', 42));
    }

    // ---------------------------------------------------------------------

    /** @param array<string,mixed> $api */
    private static function enabled(array $api): MenuBuilderApiConfig
    {
        return MenuBuilderApiConfig::fromArray(['api' => ['enabled' => true] + $api]);
    }

    /** @param array<string,mixed> $htmlAttributes */
    private function node(
        string $title = 'Home',
        ?string $url = '/',
        ?string $handle = null,
        array $htmlAttributes = [],
        ?string $icon = null,
        ?string $badge = null,
        ?string $badgeStyle = null,
        ?int $contentId = null,
        array $mobile = [],
        ?MenuBuilderMegaMenuConfig $megaMenu = null,
        ?int $megaMenuColumn = null,
    ): MenuBuilderNode {
        return new MenuBuilderNode(
            id: 1,
            handle: $handle,
            type: MenuBuilderItem::TYPE_URL,
            title: $title,
            url: $url,
            isClickable: $url !== null,
            isLinkAvailable: true,
            target: '_self',
            rel: null,
            cssClass: null,
            htmlId: null,
            htmlAttributes: $htmlAttributes,
            ariaLabel: null,
            titleAttribute: null,
            icon: $icon,
            badge: $badge,
            description: null,
            image: null,
            featured: false,
            level: 1,
            megaMenu: $megaMenu,
            megaMenuColumn: $megaMenuColumn,
            badgeStyle: $badgeStyle,
            contentId: $contentId,
            mobile: $mobile,
        );
    }
}

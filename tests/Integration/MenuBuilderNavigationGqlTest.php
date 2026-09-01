<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\elements\User;
use craft\models\GqlSchema;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderGqlHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * The navigation query through Craft's **real GraphQL layer**: a real schema
 * with a real scope, a query executed by Craft's own `Gql` service against
 * real menus in a real database.
 *
 * The unit suite (MenuBuilderGqlTest) proves what each resolver returns for a
 * given node. It proves nothing about whether Craft can build a schema out of
 * these types at all, whether the scope actually gates anything, or whether
 * the site argument reaches the resolve pipeline — a recursive type webonyx
 * rejects, or a scope check that silently passes everything, would sail
 * through every unit test and only fail against a real install.
 */
class MenuBuilderNavigationGqlTest extends CraftIntegrationTestCase
{
    /** The menu this class builds for itself: nesting, visibility rules, mobile config. */
    private static MenuBuilderGroup $navMenu;

    /** Enabled and real, but deliberately left out of the schema's scope. */
    private static MenuBuilderGroup $unscopedMenu;

    private static ?GqlSchema $schema = null;

    private static bool $navFixtureLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Craft's GraphQL result cache is keyed by (site, schema, query,
        // variables) and by nothing else — which is the very property this
        // surface is designed around. Left on, it would mean half these
        // assertions were reading a cache entry rather than exercising the
        // resolver, so each test here resolves for real.
        Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = false;

        if (self::$navFixtureLoaded) {
            return;
        }

        self::$navMenu = self::createMenu('gqlnav', 'GraphQL Navigation');
        self::$unscopedMenu = self::createMenu('gqlsecret', 'Not In Scope');

        self::addNavItem(self::$navMenu, 'Home', '/');

        $about = self::addNavItem(self::$navMenu, 'About', '/about');
        self::addChild(self::$navMenu, $about, 'Team', '/about/team');
        self::addChild(self::$navMenu, $about, 'History', '/about/history');

        // The audience gates. A GraphQL response is resolved for nobody, so
        // the members-only item must never appear and the logged-out one
        // always must.
        self::addNavItem(self::$navMenu, 'Members', '/members', visibility: [['type' => 'loggedIn']]);
        self::addNavItem(self::$navMenu, 'Sign in', '/login', visibility: [['type' => 'loggedOut']]);

        // Never resolved at all — the tree is built with includeDisabled: false.
        self::addNavItem(self::$navMenu, 'Draft page', '/draft', enabled: false);

        self::addNavItem(self::$navMenu, 'App', '/app', metadata: [
            'mobile' => ['visibility' => 'mobileOnly'],
        ]);

        self::addNavItem(self::$unscopedMenu, 'Secret', '/secret');

        self::$navFixtureLoaded = true;
    }

    // ---------------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------------

    private static function addNavItem(
        MenuBuilderGroup $group,
        string $title,
        string $url,
        array $visibility = [],
        array $metadata = [],
        bool $enabled = true,
        ?int $parentId = null,
    ): MenuBuilderItem {
        $item = new MenuBuilderItem();
        $item->groupId = (int)$group->id;
        $item->parentId = $parentId;
        $item->title = $title;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = $url;
        $item->enabled = $enabled;
        $item->visibility = $visibility;
        $item->metadata = $metadata;

        if (!MenuBuilder::getInstance()->items->save($item)) {
            throw new \RuntimeException("Could not create item \"$title\": " . json_encode($item->getErrors()));
        }

        return $item;
    }

    private static function addChild(MenuBuilderGroup $group, MenuBuilderItem $parent, string $title, string $url): MenuBuilderItem
    {
        return self::addNavItem($group, $title, $url, parentId: (int)$parent->id);
    }

    /**
     * A schema scoped the way a real token's would be: both sites, and the
     * one menu under test. `gqlsecret` and the fixture's other menus are
     * deliberately absent — that omission is what the scope tests assert on.
     */
    private static function schema(): GqlSchema
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        $scope = [MenuBuilderGqlHelper::scopeComponent((string)self::$navMenu->uid) . ':read'];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $scope[] = "sites.$site->uid:read";
        }

        return self::$schema = new GqlSchema([
            'id' => 100,
            'name' => 'MenuBuilder navigation',
            'scope' => $scope,
        ]);
    }

    /**
     * @param array<string,mixed>|null $variables
     * @return array<string,mixed>
     */
    private static function runQuery(string $query, ?array $variables = null, ?GqlSchema $schema = null): array
    {
        $gql = Craft::$app->getGql();
        $schema ??= self::schema();

        // Craft memoizes the *built* schema definition on the service, keyed
        // by nothing — so a definition another test class built from its own
        // schema would be reused here and every scope assertion below would
        // be answered by the wrong schema. Flushing is the only way to make a
        // per-schema assertion independent of what ran before it.
        $gql->flushCaches();
        $gql->setActiveSchema($schema);

        try {
            return $gql->executeQuery($schema, $query, $variables, null, true);
        } finally {
            $gql->setActiveSchema(null);
        }
    }

    /**
     * @param array<string,mixed>|null $variables
     * @return array<string,mixed>|null The `menuBuilder` payload.
     */
    private function menu(?array $variables = null, ?GqlSchema $schema = null): ?array
    {
        $result = self::runQuery(self::MENU_QUERY, $variables ?? ['handle' => 'gqlnav'], $schema);

        $this->assertArrayNotHasKey('errors', $result, 'GraphQL errors: ' . json_encode($result['errors'] ?? []));

        return $result['data']['menuBuilder'];
    }

    private const MENU_QUERY = <<<'GQL'
    query Nav($handle: String!, $site: String, $siteId: Int, $currentUri: String, $viewport: String) {
      menuBuilder(handle: $handle, site: $site, siteId: $siteId, currentUri: $currentUri, viewport: $viewport) {
        handle
        name
        uid
        itemCount
        items {
          title
          url
          type
          isActive
          isActiveAncestor
          hasChildren
          children {
            title
            url
            level
            isActive
          }
        }
      }
    }
    GQL;

    /** @return string[] Top-level titles, in tree order. */
    private function titles(?array $menu): array
    {
        return array_column($menu['items'], 'title');
    }

    // ---------------------------------------------------------------------
    // A valid query
    // ---------------------------------------------------------------------

    public function testAValidQueryReturnsTheResolvedMenu(): void
    {
        $menu = $this->menu();

        $this->assertSame('gqlnav', $menu['handle']);
        $this->assertSame('GraphQL Navigation', $menu['name']);
        $this->assertSame((string)self::$navMenu->uid, $menu['uid']);
    }

    public function testItemsComeBackResolvedAndInOrder(): void
    {
        $menu = $this->menu();

        $this->assertSame(['Home', 'About', 'Sign in', 'App'], $this->titles($menu));
        $this->assertSame('/about', $menu['items'][1]['url']);
        $this->assertSame('url', $menu['items'][1]['type']);
        $this->assertSame(count($menu['items']), $menu['itemCount']);
    }

    public function testNestedItemsAreReturnedAsChildren(): void
    {
        $about = $this->menu()['items'][1];

        $this->assertTrue($about['hasChildren']);
        $this->assertSame(['Team', 'History'], array_column($about['children'], 'title'));
        $this->assertSame([2, 2], array_column($about['children'], 'level'));
    }

    public function testDisabledItemsAreNeverReturned(): void
    {
        $this->assertNotContains('Draft page', $this->titles($this->menu()));
    }

    // ---------------------------------------------------------------------
    // Visibility — the shared-cache property
    // ---------------------------------------------------------------------

    /**
     * A GraphQL response is resolved for nobody: Craft caches it by schema
     * and query, not by caller, so an item only logged-in users may see must
     * never enter one.
     */
    public function testItemsRestrictedToLoggedInVisitorsAreNeverReturned(): void
    {
        $titles = $this->titles($this->menu());

        $this->assertNotContains('Members', $titles);
        $this->assertContains('Sign in', $titles);
    }

    /**
     * The same query, sent by an authenticated admin, returns exactly the
     * same tree. Were the audience taken from the request instead, this
     * caller's response would carry the members-only item — and, with Craft's
     * result cache on, would hand it to the next anonymous caller sharing the
     * key.
     */
    public function testAnAuthenticatedCallerGetsTheSameTreeAsAnAnonymousOne(): void
    {
        $anonymous = $this->menu();

        $admin = User::find()->admin()->one();
        $this->assertNotNull($admin, 'The integration install has an admin user.');

        Craft::$app->getUser()->setIdentity($admin);

        try {
            $authenticated = $this->menu();
        } finally {
            Craft::$app->getUser()->setIdentity(null);
        }

        $this->assertSame($anonymous, $authenticated);
        $this->assertNotContains('Members', $this->titles($authenticated));
    }

    // ---------------------------------------------------------------------
    // Active state
    // ---------------------------------------------------------------------

    public function testActiveStateIsMarkedAgainstTheCurrentUriArgument(): void
    {
        $menu = $this->menu(['handle' => 'gqlnav', 'currentUri' => 'about/team']);

        $about = $menu['items'][1];

        $this->assertFalse($about['isActive'], 'The ancestor is not the current page.');
        $this->assertTrue($about['isActiveAncestor']);
        $this->assertTrue($about['children'][0]['isActive']);
        $this->assertFalse($about['children'][1]['isActive']);
    }

    /**
     * A GraphQL request is served from the API endpoint, not from the page
     * whose navigation this is. With no `currentUri`, nothing is the current
     * page — and nothing about the request leaks into a shared response.
     */
    public function testWithoutACurrentUriNothingIsActive(): void
    {
        $menu = $this->menu();

        foreach ($menu['items'] as $item) {
            $this->assertFalse($item['isActive'], "{$item['title']} should not be active");
            $this->assertFalse($item['isActiveAncestor'], "{$item['title']} should not be an active ancestor");
        }
    }

    // ---------------------------------------------------------------------
    // Handles: unknown, disabled, malformed
    // ---------------------------------------------------------------------

    public function testAnUnknownHandleReturnsNullRatherThanAnError(): void
    {
        $this->assertNull($this->menu(['handle' => 'nosuchmenu']));
    }

    public function testADisabledMenuReturnsNull(): void
    {
        // `retired` is a real, disabled menu from the shared fixture. It is
        // granted here, so this is the disabled gate and not the scope one.
        $schema = new GqlSchema([
            'id' => 101,
            'name' => 'Disabled menu granted',
            'scope' => [MenuBuilderGqlHelper::scopeComponent((string)self::$disabledMenu->uid) . ':read'],
        ]);

        $this->assertNull($this->menu(['handle' => 'retired'], $schema));
    }

    /**
     * @dataProvider malformedHandles
     */
    public function testAMalformedHandleReturnsNullAndNeverReachesTheDatabase(string $handle): void
    {
        $this->assertNull($this->menu(['handle' => $handle]));
    }

    public static function malformedHandles(): array
    {
        return [
            'hyphenated' => ['gql-nav'],
            'wildcard' => ['*'],
            'sql-ish' => ["gqlnav' OR '1'='1"],
            'traversal' => ['../gqlnav'],
            'empty' => [''],
            'spaces' => ['gql nav'],
            'very long' => [str_repeat('a', 300)],
        ];
    }

    /** A required argument is still a schema-level error, as GraphQL defines it. */
    public function testOmittingTheHandleIsARejectedQuery(): void
    {
        $result = self::runQuery('{ menuBuilder { handle } }');

        $this->assertArrayHasKey('errors', $result);
    }

    public function testAnUnknownArgumentIsARejectedQuery(): void
    {
        $result = self::runQuery('{ menuBuilder(handle: "gqlnav", userId: 1) { handle } }');

        $this->assertArrayHasKey('errors', $result);
    }

    // ---------------------------------------------------------------------
    // Scope — the authorization gate
    // ---------------------------------------------------------------------

    /**
     * A real, enabled menu the schema doesn't name is indistinguishable from
     * one that doesn't exist. Anything else would be an enumeration oracle
     * for the install's navigation structure.
     */
    public function testAMenuOutsideTheSchemasScopeIsIndistinguishableFromAMissingOne(): void
    {
        $this->assertNull($this->menu(['handle' => 'gqlsecret']));
        $this->assertNull($this->menu(['handle' => 'nosuchmenu']));
    }

    public function testTheListOnlyContainsMenusTheSchemaNames(): void
    {
        $result = self::runQuery('{ menuBuilderNavigations { handle } }');

        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? []));
        $this->assertSame(['gqlnav'], array_column($result['data']['menuBuilderNavigations'], 'handle'));
    }

    /**
     * A schema that names no menu doesn't get the fields at all — they are
     * absent from it, introspection included, so an install that hasn't opted
     * in exposes nothing.
     */
    public function testASchemaThatNamesNoMenuHasNoMenuBuilderFields(): void
    {
        $empty = new GqlSchema(['id' => 102, 'name' => 'No menus', 'scope' => []]);

        $result = self::runQuery('{ menuBuilder(handle: "gqlnav") { handle } }', null, $empty);

        $this->assertArrayHasKey('errors', $result, 'The field should not exist in this schema at all.');
    }

    public function testTheSchemaComponentsOfferEveryMenuForReadingOnly(): void
    {
        $components = \Tahadudhiya\MenuBuilder\gql\MenuBuilderNavigationQuery::schemaComponents();

        $this->assertArrayHasKey(
            MenuBuilderGqlHelper::scopeComponent((string)self::$navMenu->uid) . ':read',
            $components,
        );

        foreach (array_keys($components) as $component) {
            $this->assertStringEndsWith(':read', $component, 'There is no GraphQL write surface for menus.');
        }
    }

    // ---------------------------------------------------------------------
    // Sites
    // ---------------------------------------------------------------------

    public function testAMenuRestrictedToOtherSitesIsNotReturnedForThisOne(): void
    {
        // `primaryOnly` is restricted to the primary site. Granted in scope,
        // so what is being tested is the site gate and nothing else.
        $schema = new GqlSchema([
            'id' => 103,
            'name' => 'Primary-only granted',
            'scope' => array_merge(
                [MenuBuilderGqlHelper::scopeComponent((string)self::$primaryOnlyMenu->uid) . ':read'],
                array_map(fn($site) => "sites.$site->uid:read", Craft::$app->getSites()->getAllSites()),
            ),
        ]);

        $this->assertNotNull($this->menu(['handle' => 'primaryOnly'], $schema), 'Available on the primary site.');
        $this->assertNull($this->menu(['handle' => 'primaryOnly', 'site' => 'secondary'], $schema));
    }

    public function testAnExplicitSiteResolvesTheMenuForThatSite(): void
    {
        $this->assertNotNull($this->menu(['handle' => 'gqlnav', 'site' => 'secondary']));
        $this->assertNotNull($this->menu(['handle' => 'gqlnav', 'siteId' => self::$secondSiteId]));
    }

    public function testASiteTheSchemaMayNotQueryReturnsNull(): void
    {
        $schema = new GqlSchema([
            'id' => 104,
            'name' => 'Primary site only',
            'scope' => [
                MenuBuilderGqlHelper::scopeComponent((string)self::$navMenu->uid) . ':read',
                'sites.' . Craft::$app->getSites()->getPrimarySite()->uid . ':read',
            ],
        ]);

        $this->assertNotNull($this->menu(['handle' => 'gqlnav', 'site' => 'default'], $schema));
        $this->assertNull($this->menu(['handle' => 'gqlnav', 'site' => 'secondary'], $schema));
    }

    public function testAnUnknownOrMalformedSiteReturnsNullRatherThanTheCurrentSite(): void
    {
        $this->assertNull($this->menu(['handle' => 'gqlnav', 'site' => 'nosuchsite']));
        $this->assertNull($this->menu(['handle' => 'gqlnav', 'site' => '*']));
        $this->assertNull($this->menu(['handle' => 'gqlnav', 'siteId' => 99999]));
    }

    /** Two site arguments that disagree are answering a question nobody asked. */
    public function testDisagreeingSiteArgumentsAreRejected(): void
    {
        $this->assertNull($this->menu([
            'handle' => 'gqlnav',
            'site' => 'default',
            'siteId' => self::$secondSiteId,
        ]));

        $this->assertNotNull($this->menu([
            'handle' => 'gqlnav',
            'site' => 'secondary',
            'siteId' => self::$secondSiteId,
        ]));
    }

    // ---------------------------------------------------------------------
    // Viewport
    // ---------------------------------------------------------------------

    public function testTheViewportArgumentReshapesTheMenu(): void
    {
        $desktop = $this->titles($this->menu(['handle' => 'gqlnav', 'viewport' => 'desktop']));
        $mobile = $this->titles($this->menu(['handle' => 'gqlnav', 'viewport' => 'mobile']));

        $this->assertNotContains('App', $desktop, 'A mobile-only item does not belong to the desktop navigation.');
        $this->assertContains('App', $mobile);
    }

    /** An unknown viewport reshapes nothing rather than emptying the menu. */
    public function testAnUnknownViewportIsIgnored(): void
    {
        $this->assertSame(
            $this->titles($this->menu()),
            $this->titles($this->menu(['handle' => 'gqlnav', 'viewport' => 'tablet'])),
        );
    }

    // ---------------------------------------------------------------------
    // What the schema does not expose
    // ---------------------------------------------------------------------

    /**
     * Asked for against the **built** schema, not against a field list: a row
     * ID that had crept back into the type would be a real, answerable query
     * here.
     */
    public function testRowIdsAreNotQueryable(): void
    {
        foreach (['id', 'parentId', 'groupId', 'enabled', 'visibility', 'metadata'] as $field) {
            $result = self::runQuery("{ menuBuilder(handle: \"gqlnav\") { items { $field } } }");

            $this->assertArrayHasKey('errors', $result, "`$field` should not exist on a navigation item.");
        }
    }

    public function testThereIsNoMutationSurface(): void
    {
        $result = self::runQuery('mutation { saveMenuBuilderItem(title: "x") { title } }');

        $this->assertArrayHasKey('errors', $result);
    }
}

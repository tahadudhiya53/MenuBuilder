<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use craft\gql\GqlEntityRegistry;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\gql\MenuBuilderNavigationItemType;
use Tahadudhiya\MenuBuilder\gql\MenuBuilderNavigationType;
use Tahadudhiya\MenuBuilder\helpers\BadgeHelper;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderGqlHelper;
use Tahadudhiya\MenuBuilder\helpers\MobileHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * The GraphQL surface's decidable half: argument normalization, the audience
 * a GraphQL request resolves for, and what each field of each type actually
 * returns for a given node.
 *
 * No booted Craft application. The registry's type prefix is set explicitly
 * (it would otherwise be read from a general config that doesn't exist here),
 * which is enough for the webonyx type objects themselves to be built — so
 * these tests cover the real `fieldDefinitions()` the real schema is built
 * from, not a copy of them. Whether Craft can *assemble* a schema out of
 * them, and what a real query returns, is MenuBuilderNavigationGqlTest's job.
 */
class MenuBuilderGqlTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        GqlEntityRegistry::setPrefix('');
    }

    // ---------------------------------------------------------------------
    // Argument normalization — every one of these is attacker-controlled
    // ---------------------------------------------------------------------

    public function testHandleAcceptsACraftHandle(): void
    {
        $this->assertSame('main', MenuBuilderGqlHelper::normalizeHandle('main'));
        $this->assertSame('footer_2', MenuBuilderGqlHelper::normalizeHandle('  footer_2  '));
    }

    /**
     * @dataProvider malformedHandles
     */
    public function testHandleRejectsAnythingThatIsntOne(mixed $value): void
    {
        $this->assertNull(MenuBuilderGqlHelper::normalizeHandle($value));
    }

    public static function malformedHandles(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'null' => [null],
            'int' => [42],
            'array' => [['main']],
            'bool' => [true],
            'leading digit' => ['1main'],
            'hyphen' => ['main-nav'],
            'sql-ish' => ["main' OR 1=1 --"],
            'wildcard' => ['*'],
            'path traversal' => ['../main'],
            'newline' => ["main\nfooter"],
            'too long' => [str_repeat('a', 256)],
        ];
    }

    public function testSiteIdAcceptsPositiveIntegersOnly(): void
    {
        $this->assertSame(2, MenuBuilderGqlHelper::normalizeSiteId(2));
        $this->assertSame(2, MenuBuilderGqlHelper::normalizeSiteId('2'));
        $this->assertNull(MenuBuilderGqlHelper::normalizeSiteId(0));
        $this->assertNull(MenuBuilderGqlHelper::normalizeSiteId(-1));
        $this->assertNull(MenuBuilderGqlHelper::normalizeSiteId('2abc'));
        $this->assertNull(MenuBuilderGqlHelper::normalizeSiteId(1.5));
        $this->assertNull(MenuBuilderGqlHelper::normalizeSiteId(null));
        $this->assertNull(MenuBuilderGqlHelper::normalizeSiteId([2]));
    }

    public function testViewportAcceptsOnlyTheTwoRealViewports(): void
    {
        $this->assertSame('mobile', MenuBuilderGqlHelper::normalizeViewport('mobile'));
        $this->assertSame('desktop', MenuBuilderGqlHelper::normalizeViewport('desktop'));
        $this->assertNull(MenuBuilderGqlHelper::normalizeViewport('tablet'));
        $this->assertNull(MenuBuilderGqlHelper::normalizeViewport('MOBILE'));
        $this->assertNull(MenuBuilderGqlHelper::normalizeViewport(null));
        $this->assertNull(MenuBuilderGqlHelper::normalizeViewport(['mobile']));
    }

    public function testCurrentUriIsTrimmedAndBounded(): void
    {
        $this->assertSame('about/team', MenuBuilderGqlHelper::normalizeCurrentUri(' about/team '));
        $this->assertNull(MenuBuilderGqlHelper::normalizeCurrentUri(''));
        $this->assertNull(MenuBuilderGqlHelper::normalizeCurrentUri(null));
        $this->assertNull(MenuBuilderGqlHelper::normalizeCurrentUri(['about']));
    }

    /**
     * `currentUri` is part of Craft's GraphQL result-cache key, so an
     * unbounded one is an unbounded number of cache entries.
     */
    public function testAnAbsurdlyLongCurrentUriIsRejected(): void
    {
        $this->assertNull(MenuBuilderGqlHelper::normalizeCurrentUri(str_repeat('a', 2049)));
        $this->assertNotNull(MenuBuilderGqlHelper::normalizeCurrentUri(str_repeat('a', 2048)));
    }

    // ---------------------------------------------------------------------
    // Schema scope
    // ---------------------------------------------------------------------

    public function testTheScopeComponentIsNamespacedAndKeyedByUid(): void
    {
        $this->assertSame(
            'menuBuilderGroups.3f2a-uid',
            MenuBuilderGqlHelper::scopeComponent('3f2a-uid'),
        );
    }

    /** An unsaved menu has no UID, so no schema can name it — and it is never readable. */
    public function testAMenuWithNoUidHasNoScopeComponent(): void
    {
        $this->assertNull(MenuBuilderGqlHelper::scopeComponent(null));
        $this->assertNull(MenuBuilderGqlHelper::scopeComponent(''));
        $this->assertNull(MenuBuilderGqlHelper::scopeComponent('   '));
    }

    // ---------------------------------------------------------------------
    // The audience — the security property this whole surface rests on
    // ---------------------------------------------------------------------

    /**
     * Craft caches GraphQL results by (site, schema, query, variables) and by
     * nothing about the caller. If the audience were the *request's*, an
     * admin's query would fill a shared cache entry with the items only
     * logged-in users may see, and the next anonymous caller would read them
     * out of it.
     */
    public function testGraphqlResolvesForNobody(): void
    {
        $context = MenuBuilderGqlHelper::anonymousContext(2, new DateTimeZone('UTC'), 'production');

        $this->assertFalse($context->isLoggedIn);
        $this->assertSame([], $context->userGroupIds);
        $this->assertSame(2, $context->currentSiteId);
        $this->assertSame('production', $context->environment);
    }

    public function testTheAnonymousContextStillCarriesTimeAndEnvironment(): void
    {
        $timezone = new DateTimeZone('Europe/London');
        $now = new DateTime('2026-01-01 12:00:00', $timezone);

        $context = MenuBuilderGqlHelper::anonymousContext(1, $timezone, 'staging', $now);

        $this->assertSame($now, $context->now);
        $this->assertSame($timezone, $context->timezone);
        $this->assertSame('staging', $context->environment);
    }

    // ---------------------------------------------------------------------
    // Value shaping
    // ---------------------------------------------------------------------

    public function testAttributeBagsBecomeNameValuePairs(): void
    {
        $this->assertSame(
            [['name' => 'data-test', 'value' => 'yes'], ['name' => 'role', 'value' => 'none']],
            MenuBuilderGqlHelper::attributePairs(['data-test' => 'yes', 'role' => 'none']),
        );

        $this->assertSame([], MenuBuilderGqlHelper::attributePairs([]));
    }

    public function testCustomFieldValuesAreExposedUnderTheAccessorThatFits(): void
    {
        $entries = MenuBuilderGqlHelper::customFieldEntries([
            'subtitle' => 'Since 1998',
            'featured' => true,
            'hidden' => false,
            'weight' => 3,
            'ratio' => 1.5,
            'photo' => 41,
        ]);

        $this->assertSame([
            ['handle' => 'subtitle', 'value' => 'Since 1998', 'booleanValue' => null, 'numberValue' => null, 'intValue' => null, 'jsonValue' => '"Since 1998"'],
            ['handle' => 'featured', 'value' => 'true', 'booleanValue' => true, 'numberValue' => null, 'intValue' => null, 'jsonValue' => 'true'],
            ['handle' => 'hidden', 'value' => 'false', 'booleanValue' => false, 'numberValue' => null, 'intValue' => null, 'jsonValue' => 'false'],
            ['handle' => 'weight', 'value' => '3', 'booleanValue' => null, 'numberValue' => 3.0, 'intValue' => 3, 'jsonValue' => '3'],
            ['handle' => 'ratio', 'value' => '1.5', 'booleanValue' => null, 'numberValue' => 1.5, 'intValue' => null, 'jsonValue' => '1.5'],
            ['handle' => 'photo', 'value' => '41', 'booleanValue' => null, 'numberValue' => 41.0, 'intValue' => 41, 'jsonValue' => '41'],
        ], $entries);
    }

    /**
     * A false boolean must not read as `""` on `value` — a bare string cast
     * of a PHP bool produces exactly that, and it is indistinguishable from
     * an empty text field.
     */
    public function testAFalseBooleanIsNotAnEmptyString(): void
    {
        $entries = MenuBuilderGqlHelper::customFieldEntries(['flag' => false]);

        $this->assertSame('false', $entries[0]['value']);
        $this->assertFalse($entries[0]['booleanValue']);
    }

    /**
     * A field whose serialized value isn't a scalar — a relation field's
     * element IDs, a Matrix field's blocks — has no honest scalar
     * representation, so every scalar accessor stays null and the value is
     * offered JSON-encoded instead. Flattening it into a string, or picking
     * one id to stand for the list, would be a guess at a shape only the
     * field itself knows.
     */
    public function testNonScalarCustomFieldValuesAreOfferedAsJson(): void
    {
        $entries = MenuBuilderGqlHelper::customFieldEntries([
            'good' => 'kept',
            'related' => [12, 15],
            'blocks' => ['type' => 'promo'],
        ]);

        $this->assertSame(['good', 'related', 'blocks'], array_column($entries, 'handle'));

        $related = $entries[1];
        $this->assertNull($related['value']);
        $this->assertNull($related['booleanValue']);
        $this->assertNull($related['numberValue']);
        $this->assertNull($related['intValue']);
        $this->assertSame('[12,15]', $related['jsonValue']);
        $this->assertSame('{"type":"promo"}', $entries[2]['jsonValue']);
    }

    /**
     * A field holding nothing is not reported as an empty field: null has no
     * entry at all, so a consumer can tell "no value" from "empty string".
     */
    public function testNullCustomFieldValuesAreDropped(): void
    {
        $entries = MenuBuilderGqlHelper::customFieldEntries([
            'good' => 'kept',
            'empty' => null,
        ]);

        $this->assertSame(['good'], array_column($entries, 'handle'));
    }

    // ---------------------------------------------------------------------
    // The item type: what each field returns
    // ---------------------------------------------------------------------

    public function testTheItemTypeNeverExposesTheRowId(): void
    {
        $fields = MenuBuilderNavigationItemType::fieldDefinitions();

        $this->assertArrayNotHasKey('id', $fields, 'A menubuilder_items row ID is an internal fact about this install.');
        $this->assertArrayNotHasKey('itemId', $fields);
        $this->assertArrayNotHasKey('parentId', $fields);
        $this->assertArrayNotHasKey('groupId', $fields);
        $this->assertArrayHasKey('handle', $fields);
    }

    /** Visibility rules, fallback behaviour and the raw metadata bag are editor-side configuration. */
    public function testTheItemTypeExposesNoEditorSideConfiguration(): void
    {
        $fields = MenuBuilderNavigationItemType::fieldDefinitions();

        foreach (['visibility', 'fallbackBehavior', 'fallbackUrl', 'metadata', 'sortOrder', 'enabled', 'elementId'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $fields);
        }
    }

    public function testItemResolversReadTheNode(): void
    {
        $node = $this->node(title: 'About', url: '/about', handle: 'about');
        $fields = MenuBuilderNavigationItemType::fieldDefinitions();

        $this->assertSame('About', $this->resolve($fields, 'title', $node));
        $this->assertSame('/about', $this->resolve($fields, 'url', $node));
        $this->assertSame('about', $this->resolve($fields, 'handle', $node));
        $this->assertSame(MenuBuilderItem::TYPE_URL, $this->resolve($fields, 'type', $node));
        $this->assertSame(1, $this->resolve($fields, 'level', $node));
        $this->assertTrue($this->resolve($fields, 'isClickable', $node));
        $this->assertFalse($this->resolve($fields, 'isDynamic', $node));
        $this->assertFalse($this->resolve($fields, 'isActive', $node));
        $this->assertFalse($this->resolve($fields, 'hasChildren', $node));
        $this->assertSame([], $this->resolve($fields, 'children', $node));
    }

    public function testActiveStateIsReadFromTheMarkedNode(): void
    {
        $node = $this->node();
        $node->isActive = true;
        $node->isActiveAncestor = true;

        $fields = MenuBuilderNavigationItemType::fieldDefinitions();

        $this->assertTrue($this->resolve($fields, 'isActive', $node));
        $this->assertTrue($this->resolve($fields, 'isActiveAncestor', $node));
    }

    public function testChildrenAreReturnedForNesting(): void
    {
        $child = $this->node(title: 'Team', url: '/about/team');
        $parent = $this->node(title: 'About', url: '/about')->withChildren([$child]);

        $fields = MenuBuilderNavigationItemType::fieldDefinitions();

        $this->assertTrue($this->resolve($fields, 'hasChildren', $parent));
        $this->assertSame([$child], $this->resolve($fields, 'children', $parent));
    }

    /**
     * The node's *safe* attribute bag, not the stored one — so an attribute
     * that would be stripped from rendered HTML is stripped from a GraphQL
     * response too.
     */
    public function testHtmlAttributesGoThroughTheSameFilterAsRenderedMarkup(): void
    {
        $node = $this->node(htmlAttributes: ['data-ok' => 'yes', 'onclick' => 'alert(1)']);
        $fields = MenuBuilderNavigationItemType::fieldDefinitions();

        $this->assertSame(
            [['name' => 'data-ok', 'value' => 'yes']],
            $this->resolve($fields, 'htmlAttributes', $node),
        );
    }

    /** An unknown badge style fails closed here exactly as it does in a template. */
    public function testBadgeFieldsFailClosed(): void
    {
        $fields = MenuBuilderNavigationItemType::fieldDefinitions();

        $styled = $this->node(badge: 'New', badgeStyle: BadgeHelper::STYLE_INFO);
        $this->assertSame('New', $this->resolve($fields, 'badge', $styled));
        $this->assertSame(BadgeHelper::STYLE_INFO, $this->resolve($fields, 'badgeStyle', $styled));
        $this->assertStringContainsString(BadgeHelper::BASE_CLASS, $this->resolve($fields, 'badgeClass', $styled));

        // A style with no text is not a badge.
        $styleOnly = $this->node(badge: null, badgeStyle: BadgeHelper::STYLE_INFO);
        $this->assertNull($this->resolve($fields, 'badge', $styleOnly));
        $this->assertNull($this->resolve($fields, 'badgeStyle', $styleOnly));
        $this->assertNull($this->resolve($fields, 'badgeClass', $styleOnly));
    }

    /**
     * An icon is exposed as a reference, never as a resolved URL: an asset's
     * URL can change without the menu changing, and the node is what gets
     * cached.
     */
    public function testIconIsExposedAsAReferenceNotAUrl(): void
    {
        $fields = MenuBuilderNavigationItemType::fieldDefinitions();

        $this->assertArrayNotHasKey('iconUrl', $fields);

        $asset = $this->node(icon: 'asset:41');
        $this->assertSame('asset', $this->resolve($fields, 'iconType', $asset));
        $this->assertSame(41, $this->resolve($fields, 'iconAssetId', $asset));
        $this->assertNull($this->resolve($fields, 'iconClass', $asset));

        // Fails closed: an unsafe class value written straight into the
        // database reads back as nothing.
        $unsafe = $this->node(icon: 'class:"><script>');
        $this->assertNull($this->resolve($fields, 'iconClass', $unsafe));
    }

    public function testMobileFieldsAreDerivedFromTheStoredBag(): void
    {
        $node = $this->node(mobile: [
            'visibility' => MobileHelper::VISIBILITY_MOBILE_ONLY,
            'order' => 3,
        ]);
        $fields = MenuBuilderNavigationItemType::fieldDefinitions();

        $this->assertSame(MobileHelper::VISIBILITY_MOBILE_ONLY, $this->resolve($fields, 'mobileVisibility', $node));
        $this->assertSame(3, $this->resolve($fields, 'mobileOrder', $node));
        $this->assertSame(MobileHelper::VIEWPORT_MOBILE, $this->resolve($fields, 'viewportAttribute', $node));
        // No children, so no disclosure to collapse.
        $this->assertFalse($this->resolve($fields, 'isMobileCollapsible', $node));
    }

    public function testMegaMenuConfigIsExposedAsItsOwnObject(): void
    {
        $config = new MenuBuilderMegaMenuConfig(columns: 3);
        $node = $this->node(megaMenu: $config, megaMenuColumn: 2);
        $fields = MenuBuilderNavigationItemType::fieldDefinitions();

        $this->assertSame($config, $this->resolve($fields, 'megaMenu', $node));
        $this->assertSame(2, $this->resolve($fields, 'megaMenuColumn', $node));
        $this->assertNull($this->resolve($fields, 'megaMenu', $this->node()));
    }

    // ---------------------------------------------------------------------
    // The menu type
    // ---------------------------------------------------------------------

    public function testTheMenuTypeExposesThePublicFactsOnly(): void
    {
        $fields = MenuBuilderNavigationType::fieldDefinitions();

        $this->assertSame(
            ['handle', 'name', 'uid', 'description', 'cssClass', 'maxDepth', 'htmlAttributes', 'itemCount', 'items'],
            array_keys($fields),
        );

        // A row ID, the site restriction list and the settings bag are an
        // install's structure, not a fact about the navigation a visitor is
        // being handed.
        foreach (['id', 'siteIds', 'settings', 'sortOrder', 'customFields', 'enabled'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $fields);
        }
    }

    public function testMenuResolversReadTheTree(): void
    {
        $group = new MenuBuilderGroup([
            'id' => 7,
            'name' => 'Main Navigation',
            'handle' => 'main',
            'description' => 'The top one',
            'cssClass' => 'nav',
            'maxDepth' => 3,
            'uid' => 'menu-uid',
            'siteIds' => [1],
        ]);
        $tree = new MenuBuilderTree($group, [$this->node(), $this->node(title: 'Two')]);
        $fields = MenuBuilderNavigationType::fieldDefinitions();

        $this->assertSame('main', $this->resolve($fields, 'handle', $tree));
        $this->assertSame('Main Navigation', $this->resolve($fields, 'name', $tree));
        $this->assertSame('menu-uid', $this->resolve($fields, 'uid', $tree));
        $this->assertSame('The top one', $this->resolve($fields, 'description', $tree));
        $this->assertSame('nav', $this->resolve($fields, 'cssClass', $tree));
        $this->assertSame(3, $this->resolve($fields, 'maxDepth', $tree));
        $this->assertSame(2, $this->resolve($fields, 'itemCount', $tree));
        $this->assertCount(2, $this->resolve($fields, 'items', $tree));
    }

    public function testMenuHtmlAttributesAreFilteredToo(): void
    {
        $group = new MenuBuilderGroup([
            'name' => 'Main',
            'handle' => 'main',
            'htmlAttributes' => ['data-nav' => 'main', 'onload' => 'alert(1)'],
        ]);
        $fields = MenuBuilderNavigationType::fieldDefinitions();

        $this->assertSame(
            [['name' => 'data-nav', 'value' => 'main']],
            $this->resolve($fields, 'htmlAttributes', new MenuBuilderTree($group, [])),
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @param array<string,array<string,mixed>> $fields */
    private function resolve(array $fields, string $name, mixed $source): mixed
    {
        $this->assertArrayHasKey($name, $fields);

        return ($fields[$name]['resolve'])($source);
    }

    private function node(
        string $title = 'Home',
        ?string $url = '/',
        ?string $handle = null,
        array $htmlAttributes = [],
        ?string $icon = null,
        ?string $badge = null,
        ?string $badgeStyle = null,
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
            mobile: $mobile,
        );
    }
}

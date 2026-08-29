<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\linktypes\ElementLinkResolver;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\services\MenuBuilderActiveResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderDynamicNavigationService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderElementService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderVisibilityService;
use Tahadudhiya\MenuBuilder\visibility\SiteRule;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

/**
 * Multi-site behaviour end to end, across three sites — English (1), German
 * (2) and French (3).
 *
 * The invariant the whole phase rests on: **nothing resolved for one site may
 * ever be served on another.** Two mechanisms enforce it, and both are
 * exercised here.
 *
 * 1. **The group gate.** A navigation group carries a site restriction
 *    (MenuBuilderGroup::$siteIds, persisted inside the group's `settings`
 *    bag). `craft.menuBuilder.get('main')` returns null on a site the group
 *    isn't available to, before any item is loaded or any cache is read.
 * 2. **The cache key.** A resolved tree is keyed by (group handle, site),
 *    because element URLs, titles and availability are all resolved against
 *    the site being rendered. Site A's tree and site B's tree are separate
 *    entries that can never be read for one another.
 *
 * Per-item site restriction (`site` visibility rule) sits inside the
 * per-request half of the pipeline and is re-evaluated on every render, so it
 * is never baked into the shared cache — the same boundary
 * MenuBuilderVisibilityTest pins for user-specific rules, checked here for
 * the site dimension.
 *
 * A site's identity throughout is its numeric ID. Renaming a site changes no
 * key, no restriction and no rule; disabling or deleting one is covered
 * below on its own terms.
 *
 * What needs a booted Craft app (the queries in ElementLinkResolver and
 * MenuBuilderDynamicNavigationService, MenuBuilderCacheService::getOrSet(),
 * MenuBuilderResolver::getTree() itself) is covered two ways: the pure
 * decisions those methods delegate to are called directly, and the wiring
 * that can only be read — that the query is site-scoped, that the site gate
 * runs before the cache read — is asserted against the source. The rest is
 * the manual testing checklist.
 */
class MenuBuilderMultiSiteTest extends TestCase
{
    private const EN = 1;
    private const DE = 2;
    private const FR = 3;

    /** A site that existed when menus were configured and has since been deleted. */
    private const REMOVED = 4;

    // =====================================================================
    // The cache key: one resolved tree per (menu, site)
    // =====================================================================

    /**
     * A cache key for one menu on one site. The third component is the
     * configuration/version digest (MenuBuilderCacheService::configVersion()),
     * held constant here so these tests speak about the site dimension only —
     * MenuBuilderCacheTest covers the version dimension.
     */
    private function key(string $handle, int $siteId): string
    {
        return MenuBuilderCacheService::cacheKey($handle, $siteId, 'cfg');
    }


    public function testEachSiteGetsItsOwnCacheEntryForTheSameMenu(): void
    {
        $keys = [
            $this->key('main', self::EN),
            $this->key('main', self::DE),
            $this->key('main', self::FR),
        ];

        $this->assertSame($keys, array_unique($keys), 'English, German and French must not share a cache entry.');
    }

    /**
     * The concrete failure this phase exists to prevent: reading the German
     * site's navigation must never hand back what was resolved for English.
     * Modelled against a plain array standing in for the cache backend, so
     * the key scheme is what's actually under test.
     */
    public function testOneSitesCachedTreeIsNeverReadableOnAnother(): void
    {
        $cache = [
            $this->key('main', self::EN) => [$this->node(1, url: '/about')],
            $this->key('main', self::DE) => [$this->node(1, url: '/de/ueber-uns')],
        ];

        $this->assertSame('/about', $cache[$this->key('main', self::EN)][0]->url);
        $this->assertSame('/de/ueber-uns', $cache[$this->key('main', self::DE)][0]->url);
        $this->assertArrayNotHasKey(
            $this->key('main', self::FR),
            $cache,
            'A site with nothing cached must miss, not fall through to another site’s entry.'
        );
    }

    /**
     * A site's name/handle is not part of its identity here — the numeric ID
     * is. Renaming "German" to "Deutsch" changes nothing that was cached
     * under it.
     */
    public function testACacheKeyDependsOnTheSiteIdOnly(): void
    {
        $this->assertSame(
            $this->key('main', self::DE),
            $this->key('main', self::DE)
        );
    }

    /**
     * Invalidation is not scoped to a site at all: an entry carries a
     * per-menu tag (MenuBuilderCacheService::groupTag(), keyed by menu ID),
     * so invalidating a menu reaches its entry on **every** site in one
     * call — including a disabled site, a site added after the entry was
     * written, and every config version the menu was ever cached under.
     *
     * That closes a hazard the previous key-enumerating implementation had
     * to work around: `getAllSiteIds()` answers differently depending on
     * where it is called from (a front-end request — a web-triggered queue
     * job running a structure move's URI updates, say — sees only enabled
     * sites), so a tree cached while a site was enabled could survive an
     * invalidation and be served the moment the site was switched back on.
     * There is no site list in the path any more.
     *
     * MenuBuilderCacheIntegrationTest proves this against a real cache
     * backend, across all three sites plus a disabled one.
     */
    public function testInvalidationIsNotScopedToASite(): void
    {
        $tag = MenuBuilderCacheService::groupTag(1);

        $this->assertSame($tag, MenuBuilderCacheService::groupTag(1));

        foreach ([self::EN, self::DE, self::FR, self::REMOVED] as $siteId) {
            $this->assertNotSame($tag, MenuBuilderCacheService::groupTag($siteId + 100), 'The tag identifies the menu, not a site.');
        }
    }

    /**
     * And it stays targeted: two menus never share a tag, so invalidating
     * one can't reach the other's entries on any site.
     */
    public function testInvalidatingOneMenuCannotReachAnother(): void
    {
        $this->assertNotSame(MenuBuilderCacheService::groupTag(1), MenuBuilderCacheService::groupTag(2));
        $this->assertNotSame($this->key('main', self::DE), $this->key('footer', self::DE));
    }

    /**
     * Site removed. Its cache entries are unreachable rather than wrong —
     * a deleted site can never be the current site again, and Craft's site
     * IDs are auto-increment, so a new site never inherits the old one's key.
     */
    public function testARemovedSitesKeyIsNeverReusedByAnotherSite(): void
    {
        $this->assertNotSame(
            $this->key('main', self::REMOVED),
            $this->key('main', 5)
        );
    }

    // =====================================================================
    // The group gate: craft.menuBuilder.get('main') on a site it isn't for
    // =====================================================================

    public function testAMenuAvailableToOneSiteOnly(): void
    {
        $group = $this->group([self::DE]);

        $this->assertTrue($group->validate());
        $this->assertTrue($group->isAvailableForSite(self::DE));
        $this->assertFalse($group->isAvailableForSite(self::EN));
        $this->assertFalse($group->isAvailableForSite(self::FR));
    }

    public function testAMenuAvailableToMultipleSites(): void
    {
        $group = $this->group([self::EN, self::DE]);

        $this->assertTrue($group->validate());
        $this->assertTrue($group->isAvailableForSite(self::EN));
        $this->assertTrue($group->isAvailableForSite(self::DE));
        $this->assertFalse($group->isAvailableForSite(self::FR), 'French was never checked, so the menu does not exist there.');
    }

    public function testAnUnrestrictedMenuIsAvailableOnEverySite(): void
    {
        $group = $this->group([]);

        foreach ([self::EN, self::DE, self::FR] as $siteId) {
            $this->assertTrue($group->isAvailableForSite($siteId));
        }
    }

    /**
     * `craft.menuBuilder.get('main')` returns null — not an empty tree — on a
     * site the group isn't available to. The gate itself is asserted above;
     * this pins that getTree() acts on it before it can return anything, and
     * before it reads or writes the cache.
     */
    public function testTheSiteGateRunsBeforeAnythingIsResolvedOrCached(): void
    {
        $body = $this->sourceOf(MenuBuilderResolver::class, 'public function getTree', 'public static function internalHosts');

        $gate = strpos($body, 'isAvailableForSite');
        $cacheRead = strpos($body, 'cache->getOrSet');
        $itemRead = strpos($body, 'items->getFlatForGroup');

        $this->assertIsInt($gate, 'getTree() must gate on the group’s site restriction.');
        $this->assertIsInt($cacheRead);
        $this->assertIsInt($itemRead);
        $this->assertLessThan($cacheRead, $gate, 'The site gate must run before the cache is read.');
        $this->assertLessThan($itemRead, $gate, 'The site gate must run before any item is loaded.');
        $this->assertStringContainsString('return null;', substr($body, $gate, $cacheRead - $gate), 'An unavailable group must return null.');
    }

    /**
     * Site removed, group level. A group restricted to a site that has since
     * been deleted stays restricted: the menu disappears everywhere rather
     * than silently becoming available to every site, which is what pruning
     * the dead ID would do.
     */
    public function testAMenuRestrictedToARemovedSiteBecomesAvailableNowhere(): void
    {
        $group = $this->group([self::REMOVED]);

        foreach ([self::EN, self::DE, self::FR] as $siteId) {
            $this->assertFalse($group->isAvailableForSite($siteId), 'A dead restriction must fail closed, never open.');
        }
    }

    public function testARemovedSiteDoesNotAffectTheRestrictionsThatSurviveIt(): void
    {
        $group = $this->group([self::DE, self::REMOVED]);

        $this->assertTrue($group->isAvailableForSite(self::DE), 'The surviving site is unaffected.');
        $this->assertFalse($group->isAvailableForSite(self::EN));
    }

    /**
     * Site renamed. The restriction is stored as IDs, so a name or handle
     * change is a no-op for availability — there is nothing to keep in step.
     */
    public function testARestrictionIsUnaffectedByASiteRename(): void
    {
        $group = $this->group([self::DE]);
        $group->name = 'Hauptnavigation';
        $group->handle = 'hauptnavigation';

        $this->assertTrue($group->validate());
        $this->assertTrue($group->isAvailableForSite(self::DE));
    }

    /**
     * A console request has no site to match a restriction against, so a
     * restricted menu is unavailable there rather than defaulting to one site.
     */
    public function testARestrictedMenuIsUnavailableWithoutACurrentSite(): void
    {
        $this->assertFalse($this->group([self::EN])->isAvailableForSite(null));
        $this->assertTrue($this->group([])->isAvailableForSite(null));
    }

    // =====================================================================
    // Project config deployment
    // =====================================================================

    /**
     * Sites live in project config; navigation groups and items do not (see
     * MenuBuilderGroupService) — the site restriction rides along inside the
     * group's own `settings` column. This is the round-trip a deployment
     * depends on: what's written for a multi-site restriction is what comes
     * back out, and it is lifted back off the user-facing settings bag.
     */
    public function testASiteRestrictionSurvivesTheSettingsRoundTrip(): void
    {
        $written = $this->settingsWithSiteIds(['renderer' => 'nav'], [self::EN, self::DE]);

        $this->assertSame([self::EN, self::DE], $written[MenuBuilderGroupService::SITE_IDS_KEY]);
        $this->assertSame('nav', $written['renderer'], 'Unrelated settings are untouched.');
    }

    public function testAnUnrestrictedMenuStoresNoSiteKeyAtAll(): void
    {
        $written = $this->settingsWithSiteIds([MenuBuilderGroupService::SITE_IDS_KEY => [self::EN]], []);

        $this->assertArrayNotHasKey(
            MenuBuilderGroupService::SITE_IDS_KEY,
            $written,
            'Clearing the restriction must remove the key, not persist an empty list a rule could fail closed on.'
        );
    }

    /**
     * A deployment replays project config, which can rewrite sites; it never
     * rewrites navigation. Group writes must therefore never be reachable
     * from a project-config path — the drift that would produce is the whole
     * reason the group service is database-only.
     */
    public function testGroupPersistenceHasNoProjectConfigPath(): void
    {
        $source = file_get_contents((new ReflectionClass(MenuBuilderGroupService::class))->getFileName());
        $code = substr($source, strpos($source, 'class MenuBuilderGroupService'));

        $this->assertStringNotContainsString('getProjectConfig', $code);
        $this->assertStringNotContainsString('ProjectConfig::', $code);
    }

    /**
     * A site save or delete — including the one a `project-config/apply`
     * performs on deploy, which reaches Sites::handleChangedSite()/
     * handleDeletedSite() and fires exactly these events — can change the
     * base URL every cached URL was built from, the language every cached
     * title was read in, or (on delete) move content to another site. No
     * element event fires for any of that.
     */
    public function testASiteSaveOrDeleteInvalidatesEveryCachedTree(): void
    {
        $source = file_get_contents((new ReflectionClass(MenuBuilderElementService::class))->getFileName());

        $this->assertStringContainsString('Sites::EVENT_AFTER_SAVE_SITE', $source);
        $this->assertStringContainsString('Sites::EVENT_AFTER_DELETE_SITE', $source);
        $this->assertStringContainsString(
            'invalidateAll()',
            $this->sourceOf(MenuBuilderElementService::class, 'public function handleSiteChange', ''),
            'A site change affects every site’s tree, so it is the one case that flushes them all.'
        );
    }

    public function testTheSiteListenersAreAttachedAlongsideTheElementOnes(): void
    {
        $body = $this->sourceOf(MenuBuilderElementService::class, 'public function attachListeners', 'public function handleSiteChange');

        $this->assertStringContainsString('EVENT_AFTER_SAVE_SITE', $body);
        $this->assertStringContainsString('EVENT_AFTER_DELETE_SITE', $body);
    }

    // =====================================================================
    // Site-specific linked elements: entry, category, asset
    // =====================================================================

    /**
     * An element's link is always re-queried against the site being
     * rendered — that is what makes a per-site URI, a per-site title and a
     * per-site enabled state show up at all.
     */
    public function testElementLinksAreResolvedAgainstTheCurrentSite(): void
    {
        $source = $this->sourceOf(ElementLinkResolver::class, 'public function resolve', 'public static function isPubliclyAvailable');

        $this->assertStringContainsString('->site(Craft::$app->getSites()->getCurrentSite())', $source);
    }

    /** @return array<string,array{class-string,string,string}> */
    public static function siteScopedElements(): array
    {
        return [
            'entry' => [Entry::class, Entry::STATUS_LIVE, 'disabled'],
            'category' => [Category::class, Category::STATUS_ENABLED, 'disabled'],
            'asset' => [Asset::class, Asset::STATUS_ENABLED, 'disabled'],
        ];
    }

    /**
     * Site-specific entry / category / asset: an element enabled on English
     * but disabled for German resolves with a per-site status, and the
     * disabled-for-this-site status must not produce a link.
     *
     * @dataProvider siteScopedElements
     * @param class-string $elementClass
     */
    public function testAnElementDisabledForOneSiteIsUnavailableThere(string $elementClass, string $availableStatus, string $siteDisabledStatus): void
    {
        $this->assertTrue(
            ElementLinkResolver::isPubliclyAvailable($elementClass, $availableStatus),
            'Available on the site it is enabled for.'
        );
        $this->assertFalse(
            ElementLinkResolver::isPubliclyAvailable($elementClass, $siteDisabledStatus),
            'Disabled for the site being rendered — no link there, even though the element is enabled globally.'
        );
    }

    /**
     * What the German site shows in place of an entry that only exists in
     * English is the item's own fallback behaviour — the same decision as
     * any other unavailable element, reached for a per-site reason.
     */
    public function testFallbackAppliesPerSiteWhenAnElementIsMissingOnThatSite(): void
    {
        $hide = $this->elementItem(MenuBuilderItem::FALLBACK_HIDE);
        $this->assertFalse(ElementLinkResolver::fallbackFor($hide)->isAvailable);

        $disable = $this->elementItem(MenuBuilderItem::FALLBACK_DISABLE_LINK);
        $this->assertNull(ElementLinkResolver::fallbackFor($disable)->url, 'Rendered as plain text on the site it is missing from.');

        $fallback = $this->elementItem(MenuBuilderItem::FALLBACK_FALLBACK_URL, '/de/kontakt');
        $this->assertSame('/de/kontakt', ElementLinkResolver::fallbackFor($fallback)->url);
    }

    /**
     * A cached node built for one site can outlive the reason it was built —
     * the resolved tree is per-site, so an entry that fell back on German
     * must not leak an English URL through the fallback path.
     */
    public function testAPerSiteFallbackUrlIsStillRevalidated(): void
    {
        $item = $this->elementItem(MenuBuilderItem::FALLBACK_FALLBACK_URL, 'javascript:alert(1)');

        $this->assertFalse(ElementLinkResolver::fallbackFor($item)->isAvailable);
    }

    // =====================================================================
    // Site-specific title and URI
    // =====================================================================

    /**
     * Site-specific title: with no title of its own, an item takes the
     * label the element resolved to on the site being rendered — so the same
     * item reads "About us" on English and "Über uns" on German.
     */
    public function testAnItemWithoutATitleTakesThePerSiteElementTitle(): void
    {
        $this->assertSame('About us', LinkAttributeHelper::resolveTitle('', 'About us'));
        $this->assertSame('Über uns', LinkAttributeHelper::resolveTitle('', 'Über uns'));
    }

    /**
     * The deliberate consequence of an editor typing a title: it overrides
     * the element's per-site title on *every* site, so a hardcoded title
     * does not translate. Worth pinning rather than discovering.
     */
    public function testAnItemTitleOverridesThePerSiteElementTitleEverywhere(): void
    {
        $this->assertSame('Menu', LinkAttributeHelper::resolveTitle('Menu', 'About us'));
        $this->assertSame('Menu', LinkAttributeHelper::resolveTitle('Menu', 'Über uns'));
    }

    public function testAnElementWithNoTitleOnThisSiteResolvesToAnEmptyLabel(): void
    {
        $this->assertSame('', LinkAttributeHelper::resolveTitle('', null));
    }

    /**
     * Site-specific URI: the same menu, the same item, two sites, two URLs —
     * and two cache entries, which is the only thing keeping them apart.
     */
    public function testTheSameItemResolvesToADifferentUriPerSite(): void
    {
        $english = $this->node(10, url: '/about-us');
        $german = $this->node(10, url: '/de/ueber-uns');

        $this->assertNotSame($english->url, $german->url);
        $this->assertNotSame(
            $this->key('main', self::EN),
            $this->key('main', self::DE)
        );
    }

    // =====================================================================
    // Site-specific visibility (per item)
    // =====================================================================

    public function testAnItemRestrictedToOneSite(): void
    {
        $rule = new SiteRule();
        $config = ['siteIds' => [self::DE]];

        $this->assertFalse($rule->passes($config, $this->context(self::EN)));
        $this->assertTrue($rule->passes($config, $this->context(self::DE)));
        $this->assertFalse($rule->passes($config, $this->context(self::FR)));
    }

    public function testAnItemRestrictedToSeveralSites(): void
    {
        $rule = new SiteRule();
        $config = ['siteIds' => [self::DE, self::FR]];

        $this->assertFalse($rule->passes($config, $this->context(self::EN)));
        $this->assertTrue($rule->passes($config, $this->context(self::DE)));
        $this->assertTrue($rule->passes($config, $this->context(self::FR)));
    }

    /**
     * Site removed, item level — the same fail-closed answer as the group
     * gate: the item disappears rather than becoming visible everywhere.
     */
    public function testAnItemRestrictedToARemovedSiteIsHiddenOnEverySite(): void
    {
        $rule = new SiteRule();

        foreach ([self::EN, self::DE, self::FR] as $siteId) {
            $this->assertFalse($rule->passes(['siteIds' => [self::REMOVED]], $this->context($siteId)));
        }
    }

    /**
     * A group restriction and an item restriction are independent gates and
     * both must pass: a menu available on German and French, holding an item
     * restricted to French, shows that item on French only — and shows
     * nothing at all on English, where the menu doesn't exist.
     */
    public function testGroupAndItemRestrictionsCompose(): void
    {
        $group = $this->group([self::DE, self::FR]);
        $service = new MenuBuilderVisibilityService();
        $item = $this->item(1, [['type' => 'site', 'siteIds' => [self::FR]]]);

        $this->assertFalse($group->isAvailableForSite(self::EN), 'The whole menu is gone on English.');

        $this->assertTrue($group->isAvailableForSite(self::DE));
        $this->assertFalse($service->isVisible($item, $this->context(self::DE)), 'The menu renders on German, without this item.');

        $this->assertTrue($group->isAvailableForSite(self::FR));
        $this->assertTrue($service->isVisible($item, $this->context(self::FR)));
    }

    // =====================================================================
    // Integration: one cached tree, three sites through the render pipeline
    // =====================================================================

    /**
     * The per-request half of the pipeline (visibility filtering) run over
     * one and the same node tree for English, German and French, with the
     * real MenuBuilderVisibilityService and the real
     * MenuBuilderResolver::filterVisible().
     *
     * Each site sees a different menu, and the tree they were all filtered
     * from is left exactly as it was — the property that makes it safe for
     * the cached tree to be shared at all.
     */
    public function testOneTreeFiltersToADifferentMenuOnEachSite(): void
    {
        $nodes = [
            $this->node(1, url: '/'),
            $this->node(2, url: '/de'),
            $this->node(3, url: '/fr'),
            $this->node(4, url: '/global'),
        ];

        $itemsById = [
            1 => $this->item(1, [['type' => 'site', 'siteIds' => [self::EN]]]),
            2 => $this->item(2, [['type' => 'site', 'siteIds' => [self::DE]]]),
            3 => $this->item(3, [['type' => 'site', 'siteIds' => [self::FR]]]),
            4 => $this->item(4),
        ];

        $this->assertSame([1, 4], $this->ids($this->filter($nodes, $itemsById, $this->context(self::EN))));
        $this->assertSame([2, 4], $this->ids($this->filter($nodes, $itemsById, $this->context(self::DE))));
        $this->assertSame([3, 4], $this->ids($this->filter($nodes, $itemsById, $this->context(self::FR))));

        $this->assertSame([1, 2, 3, 4], $this->ids($nodes), 'Filtering for one site must not consume the shared tree.');
    }

    /**
     * The same, one level down: a German-only child under a shared parent is
     * filtered out on English without taking its parent with it, and the
     * cached parent's own children list is never rewritten.
     */
    public function testChildrenAreFilteredPerSiteWithoutMutatingTheCachedTree(): void
    {
        $children = [$this->node(2, url: '/de/produkte'), $this->node(3, url: '/products')];
        $parent = $this->node(1, url: '/', children: $children);

        $itemsById = [
            1 => $this->item(1),
            2 => $this->item(2, [['type' => 'site', 'siteIds' => [self::DE]]]),
            3 => $this->item(3, [['type' => 'site', 'siteIds' => [self::EN]]]),
        ];

        $german = $this->filter([$parent], $itemsById, $this->context(self::DE));
        $english = $this->filter([$parent], $itemsById, $this->context(self::EN));

        $this->assertSame([2], $this->ids($german[0]->children));
        $this->assertSame([3], $this->ids($english[0]->children));
        $this->assertSame([2, 3], $this->ids($parent->children), 'The cached parent keeps both children.');
        $this->assertNotSame($parent, $german[0], 'Each site gets its own copy, never the cached node itself.');
    }

    /**
     * A site restriction is evaluated on every render, so it can never be
     * baked into the shared per-site cache in the way an already-cached tree
     * would be: the same item answers differently for two contexts without
     * being modified.
     */
    public function testASiteRestrictionIsAPerRequestDecision(): void
    {
        $service = new MenuBuilderVisibilityService();
        $rules = [['type' => 'site', 'siteIds' => [self::DE]]];
        $item = $this->item(1, $rules);

        $this->assertTrue($service->isVisible($item, $this->context(self::DE)));
        $this->assertFalse($service->isVisible($item, $this->context(self::EN)));
        $this->assertSame($rules, $item->visibility, 'Evaluation must not mutate the item.');
    }

    // =====================================================================
    // Integration: active state across sites and domains
    // =====================================================================

    /**
     * Three sites on three domains. A node whose URL is on the German
     * domain is the current page only when German is the site being served —
     * the host has to match, not just the path.
     */
    public function testActiveStateIsScopedToTheSiteBeingServed(): void
    {
        $resolver = new MenuBuilderActiveResolver();

        $german = $this->node(1, url: 'https://de.example.test/produkte');
        $english = $this->node(2, url: 'https://example.test/products');

        $resolver->mark([$german, $english], '/produkte', $this->servingGerman());

        $this->assertTrue($german->isActive, 'The German page being served is active.');
        $this->assertFalse($english->isActive, 'The English site’s own URL is not the page being served, despite a related path.');
    }

    /**
     * Sibling sites routinely share a path structure — `/contact` exists on
     * all three. Serving the English `/contact` must mark the English link
     * and nothing else: the French URL is a different page on a different
     * site, however identical its path.
     *
     * This is why the internal-host list is the current site's host, not
     * every site's: with the French host admitted, both links came back
     * active and `aria-current="page"` landed on two of them.
     */
    public function testAnIdenticalPathOnAnotherSiteIsNotActive(): void
    {
        $resolver = new MenuBuilderActiveResolver();

        $french = $this->node(1, url: 'https://fr.example.test/contact');
        $english = $this->node(2, url: 'https://example.test/contact');

        $resolver->mark([$french, $english], '/contact', $this->servingEnglish());

        $this->assertFalse($french->isActive, 'The French site’s /contact is a different page.');
        $this->assertTrue($english->isActive);
    }

    /**
     * A link that deliberately crosses to another site of the same install
     * still resolves active state normally when that site is the one being
     * served: the request host is then that site's host, and the request
     * host is always in the list.
     */
    public function testACrossSiteLinkIsActiveOnTheSiteItPointsAt(): void
    {
        $resolver = new MenuBuilderActiveResolver();
        $node = $this->node(1, url: 'https://fr.example.test/contact');

        $resolver->mark([$node], '/contact', $this->servingFrench());

        $this->assertTrue($node->isActive);
    }

    public function testAnExternalHostIsNeverActiveOnAnySite(): void
    {
        $resolver = new MenuBuilderActiveResolver();

        foreach ([$this->servingEnglish(), $this->servingGerman(), $this->servingFrench()] as $hosts) {
            $node = $this->node(1, url: 'https://someone-else.example.org/contact');

            $resolver->mark([$node], '/contact', $hosts);

            $this->assertFalse($node->isActive);
        }
    }

    /**
     * A base URL spelled differently from the request — `www.` against bare,
     * a proxy — is still the site being served, which is the whole reason the
     * base URL joins the request host rather than replacing it.
     */
    public function testTheCurrentSitesBaseUrlJoinsTheRequestHost(): void
    {
        $hosts = MenuBuilderResolver::internalHosts('example.test', 'https://www.example.test/');

        $this->assertSame(['example.test', 'https://www.example.test/'], $hosts);

        $node = $this->node(1, url: 'https://www.example.test/contact');
        (new MenuBuilderActiveResolver())->mark([$node], '/contact', $hosts);

        $this->assertTrue($node->isActive, 'The site’s own base URL must not be treated as a foreign host.');
    }

    public function testASiteWithoutABaseUrlLeavesJustTheRequestHost(): void
    {
        $this->assertSame(['de.example.test'], MenuBuilderResolver::internalHosts('de.example.test', null));
    }

    public function testAnUnknownRequestHostStillContributesTheCurrentSite(): void
    {
        $this->assertSame(
            ['https://de.example.test/'],
            MenuBuilderResolver::internalHosts(null, 'https://de.example.test/')
        );
    }

    public function testNoHostAtAllSkipsTheHostComparisonEntirely(): void
    {
        $this->assertSame([], MenuBuilderResolver::internalHosts(null, null));
    }

    /**
     * Only the site being rendered contributes a base URL — asserted against
     * the source because the sibling-site hosts this deliberately excludes
     * can only be gathered from a booted app.
     */
    public function testOnlyTheCurrentSiteContributesABaseUrl(): void
    {
        $source = $this->sourceOf(MenuBuilderResolver::class, 'private function currentSiteBaseUrl', '');

        $this->assertStringContainsString('getCurrentSite()->getBaseUrl()', $source);
        $this->assertStringNotContainsString('getAllSites', $source);
    }

    // =====================================================================
    // Dynamic navigation per site
    // =====================================================================

    /**
     * A dynamic item's stored config names a section/group/volume and
     * nothing about a site: the site comes from the query, which is scoped
     * to the one being rendered. That's what makes the same dynamic item
     * list German entries on German and French entries on French.
     */
    public function testADynamicSourceIsScopedByTheQueryNotTheStoredConfig(): void
    {
        $normalized = MenuBuilderDynamicNavigationService::normalizeConfig([
            'sourceType' => 'entries',
            'sourceId' => 7,
            'limit' => 5,
        ]);

        $this->assertSame(['sourceType', 'sourceId', 'limit', 'orderBy'], array_keys($normalized));
        $this->assertArrayNotHasKey('siteId', $normalized);

        $source = $this->sourceOf(MenuBuilderDynamicNavigationService::class, 'public function resolveElements', 'public static function normalizeConfig');

        $this->assertStringContainsString('getSites()->getCurrentSite()', $source);
        $this->assertStringContainsString('->site($site)', $source);
    }

    /**
     * Synthesized dynamic children carry no visibility config of their own,
     * so nothing filters them out per site on render — their site isolation
     * rests entirely on the cache being keyed per site. Both halves are
     * asserted together so neither can be dropped without the other being
     * noticed.
     */
    public function testDynamicChildrenRelyOnTheSiteKeyedCacheForIsolation(): void
    {
        $germanChildren = [$this->node(101, url: '/de/neuigkeiten/eins', isDynamic: true)];
        $parent = $this->node(1, url: '/de/neuigkeiten', children: $germanChildren);

        $filtered = $this->filter([$parent], [1 => $this->item(1)], $this->context(self::FR));

        $this->assertSame(
            [101],
            $this->ids($filtered[0]->children),
            'Nothing about a dynamic child is site-filtered at render time.'
        );
        $this->assertNotSame(
            $this->key('main', self::DE),
            $this->key('main', self::FR),
            'Which is why the German tree those children belong to is never read on French.'
        );
    }

    /**
     * A dynamic child's `id` is a Craft element ID, so it must never be
     * looked up among the menu's own items — on a multi-site install the two
     * ID spaces overlap freely, and a collision would apply an unrelated
     * item's site rule to a synthesized node.
     */
    public function testADynamicChildIdNeverPicksUpAnUnrelatedItemsSiteRule(): void
    {
        $dynamic = $this->node(2, url: '/de/neuigkeiten/eins', isDynamic: true);
        $itemsById = [2 => $this->item(2, [['type' => 'site', 'siteIds' => [self::EN]]])];

        $filtered = $this->filter([$dynamic], $itemsById, $this->context(self::DE));

        $this->assertSame([2], $this->ids($filtered), 'The colliding item’s English-only rule must not reach the dynamic node.');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @param int[] $siteIds */
    private function group(array $siteIds): MenuBuilderGroup
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main Navigation';
        $group->handle = 'main';
        $group->siteIds = $siteIds;

        return $group;
    }

    private function context(int $siteId): VisibilityContext
    {
        $timezone = new DateTimeZone('UTC');

        return new VisibilityContext(
            isLoggedIn: false,
            userGroupIds: [],
            currentSiteId: $siteId,
            now: new DateTime('now', $timezone),
            environment: 'production',
            timezone: $timezone,
        );
    }

    private function item(int $id, array $visibility = []): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->id = $id;
        $item->visibility = $visibility;

        return $item;
    }

    private function elementItem(string $fallbackBehavior, ?string $fallbackUrl = null): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->id = 1;
        $item->type = MenuBuilderItem::TYPE_ENTRY;
        $item->elementId = 55;
        $item->fallbackBehavior = $fallbackBehavior;
        $item->fallbackUrl = $fallbackUrl;

        return $item;
    }

    /** @param MenuBuilderNode[] $children */
    private function node(int $id, string $url, bool $isDynamic = false, array $children = []): MenuBuilderNode
    {
        $node = new MenuBuilderNode(
            id: $id,
            handle: null,
            type: $isDynamic ? MenuBuilderItem::TYPE_DYNAMIC : MenuBuilderItem::TYPE_URL,
            title: 'Node ' . $id,
            url: $url,
            isClickable: true,
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
            isDynamic: $isDynamic,
        );

        $node->children = $children;

        return $node;
    }

    /**
     * What MenuBuilderResolver hands the active resolver on a real render of
     * each site: the host being requested plus that site's own base URL.
     *
     * @return string[]
     */
    private function servingEnglish(): array
    {
        return MenuBuilderResolver::internalHosts('example.test', 'https://example.test/');
    }

    /** @return string[] */
    private function servingGerman(): array
    {
        return MenuBuilderResolver::internalHosts('de.example.test', 'https://de.example.test/');
    }

    /** @return string[] */
    private function servingFrench(): array
    {
        return MenuBuilderResolver::internalHosts('fr.example.test', 'https://fr.example.test/');
    }

    /**
     * @param MenuBuilderNode[] $nodes
     * @param array<int,MenuBuilderItem> $itemsById
     * @return MenuBuilderNode[]
     */
    private function filter(array $nodes, array $itemsById, VisibilityContext $context): array
    {
        $method = new ReflectionMethod(MenuBuilderResolver::class, 'filterVisible');

        return $method->invoke(new MenuBuilderResolver(), $nodes, $itemsById, new MenuBuilderVisibilityService(), $context);
    }

    /**
     * @param array<string,mixed> $settings
     * @param int[] $siteIds
     * @return array<string,mixed>
     */
    private function settingsWithSiteIds(array $settings, array $siteIds): array
    {
        $method = new ReflectionMethod(MenuBuilderGroupService::class, 'settingsWithSiteIds');

        return $method->invoke(new MenuBuilderGroupService(), $settings, $siteIds);
    }

    /** @param MenuBuilderNode[] $nodes */
    private function ids(array $nodes): array
    {
        return array_map(fn(MenuBuilderNode $node) => $node->id, $nodes);
    }

    /**
     * The source of one method, for the handful of multi-site invariants
     * that live in wiring a booted-app-free test can't execute: which site
     * list a call asks for, and what order getTree() does things in.
     *
     * @param class-string $class
     */
    private function sourceOf(string $class, string $from, string $to): string
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        $start = strpos($source, $from);

        $this->assertIsInt($start, "{$class} no longer declares “{$from}”.");

        if ($to === '') {
            return substr($source, $start);
        }

        $end = strpos($source, $to, $start);

        $this->assertIsInt($end, "{$class} no longer declares “{$to}”.");

        return substr($source, $start, $end - $start);
    }
}

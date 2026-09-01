<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\services\MenuBuilderActiveResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderElementService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderVisibilityService;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;
use yii\caching\ArrayCache;

/**
 * Cache keying, the config/version half of the key, and the element-save
 * invalidation that keeps those keys fresh.
 *
 * The parts that read and write an actual cache backend are exercised
 * end to end in MenuBuilderCacheIntegrationTest; the pure key-building and
 * the decision of *which* menus an element change invalidates are covered
 * here.
 */
class MenuBuilderCacheTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Cache keys: one entry per (menu, site, configuration/version)
    // ---------------------------------------------------------------------

    private const VERSION = 'abc123';

    private function group(int $id = 1, string $handle = 'main', ?string $dateUpdated = '2026-08-01 09:00:00'): MenuBuilderGroup
    {
        $group = new MenuBuilderGroup();
        $group->id = $id;
        $group->handle = $handle;
        $group->name = ucfirst($handle);
        $group->dateUpdated = $dateUpdated;

        return $group;
    }

    public function testSameHandleOnDifferentSitesProducesDifferentKeys(): void
    {
        $siteAKey = MenuBuilderCacheService::cacheKey('main', 1, self::VERSION);
        $siteBKey = MenuBuilderCacheService::cacheKey('main', 2, self::VERSION);

        $this->assertNotSame($siteAKey, $siteBKey);
    }

    public function testSameHandleSiteAndVersionProducesTheSameKey(): void
    {
        $this->assertSame(
            MenuBuilderCacheService::cacheKey('main', 1, self::VERSION),
            MenuBuilderCacheService::cacheKey('main', 1, self::VERSION)
        );
    }

    public function testDifferentHandlesOnTheSameSiteProduceDifferentKeys(): void
    {
        $mainKey = MenuBuilderCacheService::cacheKey('main', 1, self::VERSION);
        $footerKey = MenuBuilderCacheService::cacheKey('footer', 1, self::VERSION);

        $this->assertNotSame($mainKey, $footerKey);
    }

    /**
     * The third dimension of the key: two payloads built under different
     * configuration/plugin versions are different payloads and must never
     * share an entry.
     */
    public function testTheSameMenuOnTheSameSiteUnderADifferentVersionIsADifferentKey(): void
    {
        $this->assertNotSame(
            MenuBuilderCacheService::cacheKey('main', 1, 'v1'),
            MenuBuilderCacheService::cacheKey('main', 1, 'v2')
        );
    }

    /**
     * Guards against a key scheme where concatenation could collide across
     * different (handle, siteId) pairs, e.g. site 1 + handle "2main" vs.
     * site 12 + handle "main".
     */
    public function testKeysDoNotCollideAcrossSiteAndHandleBoundary(): void
    {
        $this->assertNotSame(
            MenuBuilderCacheService::cacheKey('2main', 1, self::VERSION),
            MenuBuilderCacheService::cacheKey('main', 12, self::VERSION)
        );
    }

    // ---------------------------------------------------------------------
    // The configuration/version half of the key
    // ---------------------------------------------------------------------

    public function testAnUnchangedMenuKeepsItsConfigVersion(): void
    {
        $this->assertSame(
            MenuBuilderCacheService::configVersion($this->group(), '1.0.0'),
            MenuBuilderCacheService::configVersion($this->group(), '1.0.0')
        );
    }

    /**
     * `dateUpdated` moves on every menu save, so editing a menu reads a
     * fresh key by construction — independently of the invalidation that
     * also runs for it.
     */
    public function testEditingAMenuChangesItsConfigVersion(): void
    {
        $this->assertNotSame(
            MenuBuilderCacheService::configVersion($this->group(dateUpdated: '2026-08-01 09:00:00'), '1.0.0'),
            MenuBuilderCacheService::configVersion($this->group(dateUpdated: '2026-08-01 09:05:00'), '1.0.0')
        );
    }

    public function testRenamingAMenusHandleChangesItsConfigVersion(): void
    {
        $this->assertNotSame(
            MenuBuilderCacheService::configVersion($this->group(handle: 'main'), '1.0.0'),
            MenuBuilderCacheService::configVersion($this->group(handle: 'primary'), '1.0.0')
        );
    }

    /**
     * A handle can be freed by a delete and reused by a *different* menu.
     * The new menu must not read what the old one cached, even if its
     * `dateUpdated` happened to match.
     */
    public function testAReusedHandleOnANewMenuIsADifferentConfigVersion(): void
    {
        $this->assertNotSame(
            MenuBuilderCacheService::configVersion($this->group(id: 1), '1.0.0'),
            MenuBuilderCacheService::configVersion($this->group(id: 2), '1.0.0')
        );
    }

    /**
     * A plugin upgrade must not read entries written by the previous
     * version's code.
     */
    public function testAPluginSchemaVersionBumpChangesEveryConfigVersion(): void
    {
        $this->assertNotSame(
            MenuBuilderCacheService::configVersion($this->group(), '1.0.0'),
            MenuBuilderCacheService::configVersion($this->group(), '1.1.0')
        );
    }

    /**
     * A cache entry *is* a serialized MenuBuilderNode graph, so the payload
     * classes' own shape is part of the version: adding or renaming a
     * property on one of them would otherwise unserialize an old entry into
     * an object with uninitialized readonly properties, and hand Twig a
     * half-built node.
     */
    public function testThePayloadVersionIsTheShapeOfTheCachedClasses(): void
    {
        $this->assertSame(
            MenuBuilderCacheService::shapeDigest(MenuBuilderCacheService::PAYLOAD_CLASSES),
            MenuBuilderCacheService::payloadVersion()
        );
        $this->assertContains(MenuBuilderNode::class, MenuBuilderCacheService::PAYLOAD_CLASSES);
    }

    public function testAddingAPropertyToACachedClassChangesTheDigest(): void
    {
        $this->assertNotSame(
            MenuBuilderCacheService::shapeDigest([CachedPayloadShapeV1::class]),
            MenuBuilderCacheService::shapeDigest([CachedPayloadShapeV2::class])
        );
    }

    public function testTheDigestCoversEveryCachedClassNotJustTheFirst(): void
    {
        $this->assertNotSame(
            MenuBuilderCacheService::shapeDigest([CachedPayloadShapeV1::class]),
            MenuBuilderCacheService::shapeDigest([CachedPayloadShapeV1::class, CachedPayloadShapeV2::class])
        );
    }

    public function testTheSameShapeAlwaysDigestsTheSameWay(): void
    {
        $this->assertSame(
            MenuBuilderCacheService::shapeDigest([CachedPayloadShapeV1::class]),
            MenuBuilderCacheService::shapeDigest([CachedPayloadShapeV1::class])
        );
    }

    // ---------------------------------------------------------------------
    // Invalidation tags: per menu, plus one global
    // ---------------------------------------------------------------------

    public function testEachMenuHasItsOwnInvalidationTag(): void
    {
        $this->assertNotSame(MenuBuilderCacheService::groupTag(1), MenuBuilderCacheService::groupTag(2));
    }

    public function testAMenusTagIsKeyedByIdNotHandleSoARenameCannotOrphanIt(): void
    {
        $this->assertSame(MenuBuilderCacheService::groupTag(1), MenuBuilderCacheService::groupTag(1));
        $this->assertStringContainsString('1', MenuBuilderCacheService::groupTag(1));
    }

    public static function watchedElementClasses(): array
    {
        return [
            'entry' => [Entry::class, 'entries'],
            'category' => [Category::class, 'categories'],
            'asset' => [Asset::class, 'assets'],
        ];
    }

    // ---------------------------------------------------------------------
    // Element saves: which groups a changed element invalidates
    // ---------------------------------------------------------------------

    /** @dataProvider watchedElementClasses */
    public function testWatchedElementClassesMapToTheirDynamicSourceType(string $elementClass, string $expected): void
    {
        $this->assertSame($expected, MenuBuilderElementService::sourceTypeForElement($elementClass));
    }

    public function testEveryMappedSourceTypeIsOneMenuBuilderActuallySupports(): void
    {
        foreach (self::watchedElementClasses() as [$elementClass, $expected]) {
            $this->assertContains($expected, MenuBuilderItem::DYNAMIC_SOURCE_TYPES);
        }
    }

    public function testASubclassOfAWatchedElementStillMaps(): void
    {
        $this->assertSame('entries', MenuBuilderElementService::sourceTypeForElement(SubclassedEntry::class));
    }

    public static function unwatchedElementClasses(): array
    {
        return [
            'user' => [User::class],
            'global set' => [GlobalSet::class],
        ];
    }

    /**
     * A menu item can't link to these, so saving one must not be able to
     * reach any menu cache at all.
     *
     * @dataProvider unwatchedElementClasses
     */
    public function testUnlinkableElementTypesMapToNothing(string $elementClass): void
    {
        $this->assertNull(MenuBuilderElementService::sourceTypeForElement($elementClass));
    }

    public function testADynamicSourceMatchesItsOwnContainer(): void
    {
        $this->assertTrue(MenuBuilderElementService::dynamicSourceMatches(
            ['sourceType' => 'entries', 'sourceId' => 7],
            'entries',
            7
        ));
    }

    public function testADynamicSourceDoesNotMatchADifferentContainerOfTheSameType(): void
    {
        $this->assertFalse(MenuBuilderElementService::dynamicSourceMatches(
            ['sourceType' => 'entries', 'sourceId' => 7],
            'entries',
            8
        ));
    }

    /**
     * Saving an asset must not invalidate a group whose dynamic items only
     * list entries — the source type has to match, not just the container ID.
     */
    public function testADynamicSourceDoesNotMatchAnotherElementType(): void
    {
        $this->assertFalse(MenuBuilderElementService::dynamicSourceMatches(
            ['sourceType' => 'entries', 'sourceId' => 7],
            'assets',
            7
        ));
    }

    /**
     * A stored `sourceId` can legitimately be a numeric string (posted
     * config, imported config) — it's normalized the same way the render-time
     * query normalizes it, so it must match the same container.
     */
    public function testANumericStringSourceIdStillMatches(): void
    {
        $this->assertTrue(MenuBuilderElementService::dynamicSourceMatches(
            ['sourceType' => 'entries', 'sourceId' => '7'],
            'entries',
            7
        ));
    }

    /**
     * Ignored config in = no invalidation out: a config the dynamic service
     * would refuse to run can't produce menu content, so it can never
     * justify flushing a cached tree.
     */
    public static function unusableConfigs(): array
    {
        return [
            'empty' => [[]],
            'no source id' => [['sourceType' => 'entries']],
            'zero source id' => [['sourceType' => 'entries', 'sourceId' => 0]],
            'unknown source type' => [['sourceType' => 'users', 'sourceId' => 7]],
            'boolean source id' => [['sourceType' => 'entries', 'sourceId' => true]],
        ];
    }

    /** @dataProvider unusableConfigs */
    public function testAnUnusableDynamicSourceNeverMatches(array $config): void
    {
        $this->assertFalse(MenuBuilderElementService::dynamicSourceMatches($config, 'entries', 7));
        $this->assertFalse(MenuBuilderElementService::dynamicSourceMatches($config, 'entries', null));
    }

    /**
     * An element with no determinable container (a nested entry has no
     * `sectionId`) must fail *open* — matching every dynamic source of the
     * right type rather than leaving a menu stale.
     */
    public function testAnUnknownContainerMatchesEveryDynamicSourceOfThatType(): void
    {
        $this->assertTrue(MenuBuilderElementService::dynamicSourceMatches(
            ['sourceType' => 'entries', 'sourceId' => 7],
            'entries',
            null
        ));
        $this->assertFalse(MenuBuilderElementService::dynamicSourceMatches(
            ['sourceType' => 'categories', 'sourceId' => 7],
            'entries',
            null
        ));
    }

    /**
     * The ceiling that bounds the one staleness no event can announce: a
     * pending entry going live, or a live entry expiring, on a clock.
     */
    public function testCraftsCacheDurationBecomesTheTreeCacheCeiling(): void
    {
        $this->assertSame(86400, MenuBuilderCacheService::resolveDuration(86400));
        $this->assertSame(60, MenuBuilderCacheService::resolveDuration('60'));
    }

    public static function noExpiryDurations(): array
    {
        return [
            'zero means forever in Craft' => [0],
            'negative' => [-1],
            'null' => [null],
            'non-numeric' => ['forever'],
        ];
    }

    /** @dataProvider noExpiryDurations */
    public function testANonPositiveCacheDurationMeansNoExpiry(mixed $configured): void
    {
        $this->assertNull(MenuBuilderCacheService::resolveDuration($configured));
    }

    // =====================================================================
    // The cache in use: hits, misses, invalidation and isolation
    // =====================================================================

    private const EN = 1;
    private const DE = 2;

    /** A site that is currently disabled — its entries must still invalidate. */
    private const DISABLED = 3;

    // =====================================================================
    // Hit and miss
    // =====================================================================

    public function testAMissBuildsTheTreeOnceAndCachesIt(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $tree = $cache->getOrSet($group, $this->generator(['/about']));

        $this->assertSame(['/about'], $this->urls($tree));
        $this->assertSame(1, $this->builds, 'A miss builds exactly once.');
    }

    public function testAHitServesTheCachedTreeWithoutBuildingAgain(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $cache->getOrSet($group, $this->generator(['/about']));
        $second = $cache->getOrSet($group, $this->generator(['/about']));

        $this->assertSame(1, $this->builds, 'The second render must be a cache hit.');
        $this->assertSame(['/about'], $this->urls($second));
    }

    /**
     * A hit is a *copy*, not the same object graph — the entry is serialized,
     * so one request's tree can never be mutated into another request's.
     */
    public function testAHitReturnsItsOwnObjectGraph(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $first = $cache->getOrSet($group, $this->generator(['/about']));
        $second = $cache->getOrSet($group, $this->generator(['/about']));

        $this->assertNotSame($first[0], $second[0]);
        $this->assertSame($first[0]->url, $second[0]->url);
    }

    public function testAnUnsavedMenuIsResolvedFreshAndNeverCached(): void
    {
        $cache = $this->service();
        $group = $this->group();
        $group->id = null;

        $cache->getOrSet($group, $this->generator(['/about']));
        $cache->getOrSet($group, $this->generator(['/about']));

        $this->assertSame(2, $this->builds, 'A menu with no ID has no identity to cache under.');
    }

    // =====================================================================
    // Targeted invalidation
    // =====================================================================

    public function testInvalidatingAMenuMakesTheNextRenderRebuild(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $cache->getOrSet($group, $this->generator(['/about']));
        $cache->invalidateGroupId(1);
        $rebuilt = $cache->getOrSet($group, $this->generator(['/about-us']));

        $this->assertSame(2, $this->builds);
        $this->assertSame(['/about-us'], $this->urls($rebuilt), 'The rebuilt tree, not the invalidated one.');
    }

    /**
     * The requirement this phase exists for: a change that affects one menu
     * must not cost every other menu its cache.
     */
    public function testInvalidatingOneMenuLeavesEveryOtherMenuCached(): void
    {
        $cache = $this->service();
        $main = $this->group(1, 'main');
        $footer = $this->group(2, 'footer');
        $utility = $this->group(3, 'utility');

        $cache->getOrSet($main, $this->generator(['/main']));
        $cache->getOrSet($footer, $this->generator(['/footer']));
        $cache->getOrSet($utility, $this->generator(['/utility']));
        $this->assertSame(3, $this->builds);

        $cache->invalidateGroupId(1);

        $cache->getOrSet($footer, $this->generator(['/footer']));
        $cache->getOrSet($utility, $this->generator(['/utility']));
        $this->assertSame(3, $this->builds, 'Only the changed menu may lose its entry.');

        $cache->getOrSet($main, $this->generator(['/main']));
        $this->assertSame(4, $this->builds, 'And the changed menu must actually lose it.');
    }

    /**
     * One call, every site: an entry is tagged by menu ID, so invalidation
     * never has to enumerate site IDs (which Craft answers differently
     * depending on where the call is made from) and can't miss a site —
     * including one that is currently disabled.
     */
    public function testInvalidatingAMenuReachesEverySitesEntry(): void
    {
        $cache = $this->service();
        $group = $this->group();

        foreach ([self::EN, self::DE, self::DISABLED] as $siteId) {
            $cache->siteId = $siteId;
            $cache->getOrSet($group, $this->generator(['/site-' . $siteId]));
        }

        $this->assertSame(3, $this->builds);

        $cache->invalidateGroupId(1);

        foreach ([self::EN, self::DE, self::DISABLED] as $siteId) {
            $cache->siteId = $siteId;
            $cache->getOrSet($group, $this->generator(['/site-' . $siteId]));
        }

        $this->assertSame(6, $this->builds, 'Every site’s entry for the menu must be gone.');
    }

    /**
     * Invalidation reaches entries written under an *earlier* config version
     * too — the tag doesn't carry the version, so nothing is orphaned in a
     * readable state by a menu edit.
     */
    public function testInvalidationReachesEntriesFromEveryPastConfigVersion(): void
    {
        $cache = $this->service();

        $before = $this->group(dateUpdated: '2026-08-01 09:00:00');
        $after = $this->group(dateUpdated: '2026-08-02 09:00:00');

        $cache->getOrSet($before, $this->generator(['/old']));
        $cache->getOrSet($after, $this->generator(['/new']));
        $this->assertSame(2, $this->builds, 'A menu edit reads a different key.');

        $cache->invalidateGroupId(1);

        $cache->getOrSet($before, $this->generator(['/old']));
        $this->assertSame(3, $this->builds, 'The pre-edit entry must not survive an invalidation.');
    }

    /**
     * The one genuinely global change (a site save or delete) still works —
     * and it is the only thing that should ever behave this way.
     */
    public function testInvalidateAllClearsEveryMenuOnEverySite(): void
    {
        $cache = $this->service();
        $main = $this->group(1, 'main');
        $footer = $this->group(2, 'footer');

        $cache->getOrSet($main, $this->generator(['/main']));
        $cache->siteId = self::DE;
        $cache->getOrSet($footer, $this->generator(['/footer']));

        $cache->invalidateAll();

        $cache->siteId = self::EN;
        $cache->getOrSet($main, $this->generator(['/main']));
        $cache->siteId = self::DE;
        $cache->getOrSet($footer, $this->generator(['/footer']));

        $this->assertSame(4, $this->builds);
    }

    // =====================================================================
    // Multiple sites: site A must never receive site B's cache
    // =====================================================================

    public function testEachSiteBuildsAndReadsItsOwnTree(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $cache->siteId = self::EN;
        $english = $cache->getOrSet($group, $this->generator(['/about']));

        $cache->siteId = self::DE;
        $german = $cache->getOrSet($group, $this->generator(['/de/ueber-uns']));

        $this->assertSame(['/about'], $this->urls($english));
        $this->assertSame(['/de/ueber-uns'], $this->urls($german), 'German must not be served the English tree.');
        $this->assertSame(2, $this->builds);

        // And back again: both are still their own cached entry.
        $cache->siteId = self::EN;
        $this->assertSame(['/about'], $this->urls($cache->getOrSet($group, $this->generator(['/never']))));
        $this->assertSame(2, $this->builds);
    }

    // =====================================================================
    // Stale entries
    // =====================================================================

    /**
     * A menu edit moves `dateUpdated`, which is part of the key, so the tree
     * built under the old configuration is not merely invalidated — it is
     * unreadable. Belt and braces with the invalidation the save also runs.
     */
    public function testAnEditedMenuNeverReadsThePreEditTree(): void
    {
        $cache = $this->service();

        $cache->getOrSet($this->group(dateUpdated: '2026-08-01 09:00:00'), $this->generator(['/old']));
        $rebuilt = $cache->getOrSet($this->group(dateUpdated: '2026-08-02 09:00:00'), $this->generator(['/new']));

        $this->assertSame(['/new'], $this->urls($rebuilt));
    }

    /**
     * A renamed menu, and a *different* menu that later takes the freed
     * handle, both read their own key — never the entry the old menu left at
     * that handle.
     */
    public function testAReusedHandleDoesNotInheritTheOldMenusTree(): void
    {
        $cache = $this->service();

        $cache->getOrSet($this->group(1, 'main'), $this->generator(['/old-menu']));

        $newMenu = $this->group(2, 'main');
        $tree = $cache->getOrSet($newMenu, $this->generator(['/new-menu']));

        $this->assertSame(['/new-menu'], $this->urls($tree));
    }

    /**
     * An upgrade that changes the plugin's schema version reads new keys, so
     * an entry serialized by the previous version's code can never be
     * unserialized into the new one's classes.
     */
    public function testAnUpgradeNeverReadsThePreviousVersionsEntries(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $cache->getOrSet($group, $this->generator(['/before-upgrade']));

        $cache->schema = '1.1.0';
        $tree = $cache->getOrSet($group, $this->generator(['/after-upgrade']));

        $this->assertSame(['/after-upgrade'], $this->urls($tree));
    }

    /**
     * Anything at the key that isn't the payload shape this version writes
     * (a foreign value, a truncated entry, a backend handing back junk)
     * rebuilds instead of reaching Twig.
     */
    public function testAForeignValueAtTheKeyIsRebuiltRatherThanServed(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $cache->backing->set(
            MenuBuilderCacheService::cacheKey($group->handle, self::EN, MenuBuilderCacheService::configVersion($group, '1.0.0')),
            'not a tree'
        );

        $tree = $cache->getOrSet($group, $this->generator(['/about']));

        $this->assertSame(['/about'], $this->urls($tree));
        $this->assertSame(1, $this->builds);
    }

    // =====================================================================
    // Transactions: an invalidation must land after the commit
    // =====================================================================

    public function testAnInvalidationInsideATransactionWaitsForIt(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $cache->getOrSet($group, $this->generator(['/about']));

        $cache->inTransaction = true;
        $cache->invalidateGroupId(1);

        $this->assertTrue($cache->hasPendingInvalidations());
        $this->assertSame(['/about'], $this->urls($cache->getOrSet($group, $this->generator(['/never']))), 'Nothing is invalidated yet.');

        $cache->endTransaction();

        $this->assertFalse($cache->hasPendingInvalidations());
        $this->assertSame(['/rebuilt'], $this->urls($cache->getOrSet($group, $this->generator(['/rebuilt']))));
    }

    /**
     * The concrete race this deferral exists for. A bulk item operation runs
     * inside one transaction; every item it saves invalidates. If that
     * invalidation ran immediately, a concurrent front-end request could
     * rebuild the tree from **pre-commit** data, re-cache it, and nothing
     * would ever invalidate it again — the bulk change would be invisible
     * until something unrelated happened to flush the menu.
     */
    public function testAConcurrentRequestCannotRecachePreCommitData(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $cache->inTransaction = true;
        $cache->invalidateGroupId(1);

        // The concurrent request: reads, misses, and caches what the database
        // says *right now* — which is the not-yet-committed old state.
        $cache->getOrSet($group, $this->generator(['/pre-commit']));

        $cache->endTransaction();

        $tree = $cache->getOrSet($group, $this->generator(['/post-commit']));
        $this->assertSame(['/post-commit'], $this->urls($tree), 'The pre-commit tree must not survive the commit.');
    }

    /**
     * A rollback flushes the queue too. Nothing changed in the database, so
     * the extra rebuild is wasted work rather than a wrong answer — and it
     * covers the concurrent re-cache above, which happened whether or not
     * the transaction went on to commit.
     */
    public function testARollbackAlsoFlushesTheQueue(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $cache->getOrSet($group, $this->generator(['/about']));

        $cache->inTransaction = true;
        $cache->invalidateGroupId(1);
        $cache->endTransaction(committed: false);

        $this->assertSame(['/rebuilt'], $this->urls($cache->getOrSet($group, $this->generator(['/rebuilt']))));
    }

    /**
     * A bulk operation invalidates once per item; the queue collapses those
     * into one invalidation per menu, and keeps them apart from another
     * menu's.
     */
    public function testTheQueueCollapsesRepeatsAndStaysPerMenu(): void
    {
        $cache = $this->service();
        $main = $this->group(1, 'main');
        $footer = $this->group(2, 'footer');

        $cache->getOrSet($main, $this->generator(['/main']));
        $cache->getOrSet($footer, $this->generator(['/footer']));

        $cache->inTransaction = true;
        $cache->invalidateGroupId(1);
        $cache->invalidateGroupId(1);
        $cache->invalidateGroupIds([1, 1]);
        $cache->endTransaction();

        $this->assertSame(2, $this->builds);
        $cache->getOrSet($footer, $this->generator(['/footer']));
        $this->assertSame(2, $this->builds, 'The untouched menu keeps its entry.');
        $cache->getOrSet($main, $this->generator(['/main']));
        $this->assertSame(3, $this->builds);
    }

    public function testAnInvalidationOutsideATransactionIsNotQueued(): void
    {
        $cache = $this->service();

        $cache->invalidateGroupId(1);

        $this->assertFalse($cache->hasPendingInvalidations());
    }

    
    // =====================================================================
    // The boundary: two users, two days and two pages share one entry
    // =====================================================================

    /**
     * User A is in the group an item is restricted to; user B is not. Both
     * requests read the **same** cache entry, and each gets its own answer —
     * the entry itself is never narrowed, widened or written to.
     */
    public function testTwoUsersShareOneEntryWithoutLeakingVisibility(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $itemsById = [
            1 => $this->item(1),
            2 => $this->item(2, [['type' => 'userGroup', 'groupIds' => [7]]]),
        ];

        $build = fn() => [$this->node(1, '/public'), $this->node(2, '/members')];


        // User A: in group 7.
        $treeA = $this->filter($cache->getOrSet($group, $build), $itemsById, $this->context(isLoggedIn: true, userGroupIds: [7]));
        // User B: logged in, not in group 7 — served from the cache A filled.
        $treeB = $this->filter($cache->getOrSet($group, $build), $itemsById, $this->context(isLoggedIn: true, userGroupIds: [9]));
        // And an anonymous visitor, third on the same entry.
        $anonymous = $this->filter($cache->getOrSet($group, $build), $itemsById, $this->context());

        $this->assertSame(['/public', '/members'], $this->urls($treeA), 'User A sees the members-only item.');
        $this->assertSame(['/public'], $this->urls($treeB), 'User B must not.');
        $this->assertSame(['/public'], $this->urls($anonymous));

        // One entry served all three, and it still holds both nodes: B's
        // narrower answer was never written back onto the shared tree.
        $this->assertSame(
            ['/public', '/members'],
            $this->urls($cache->getOrSet($group, fn() => $this->fail('The entry must still be a hit.'))),
        );
    }

    /**
     * A date-range item is visible yesterday and hidden today. If the
     * decision were cached, yesterday's answer would outlive the day — so
     * the same entry is filtered against each request's own clock.
     */
    public function testYesterdaysVisibilityDoesNotSurviveIntoToday(): void
    {
        $cache = $this->service();
        $group = $this->group();

        $itemsById = [
            1 => $this->item(1),
            2 => $this->item(2, [['type' => 'dateRange', 'end' => '2026-08-02 00:00:00']]),
        ];

        $builds = 0;
        $build = function() use (&$builds) {
            $builds++;

            return [$this->node(1, '/always'), $this->node(2, '/until-august')];
        };

        $yesterday = $this->filter($cache->getOrSet($group, $build), $itemsById, $this->context(now: '2026-08-01 12:00:00'));
        $today = $this->filter($cache->getOrSet($group, $build), $itemsById, $this->context(now: '2026-08-03 12:00:00'));

        $this->assertSame(['/always', '/until-august'], $this->urls($yesterday));
        $this->assertSame(['/always'], $this->urls($today), 'The expired item must not be served from yesterday’s cache.');
        $this->assertSame(1, $builds, 'Both days read one cache entry — the filtering is what differs.');
    }

    /**
     * Active state is decided from the request URI, after the cache read, on
     * the copies visibility filtering produced. One page's active item must
     * never appear on another page.
     */
    public function testOnePagesActiveItemNeverAppearsOnAnother(): void
    {
        $cache = $this->service();
        $group = $this->group();
        $itemsById = [1 => $this->item(1), 2 => $this->item(2)];

        $builds = 0;
        $build = function() use (&$builds) {
            $builds++;

            return [$this->node(1, '/about'), $this->node(2, '/contact')];
        };
        $active = new MenuBuilderActiveResolver();

        $onAbout = $this->filter($cache->getOrSet($group, $build), $itemsById, $this->context());
        $active->mark($onAbout, '/about');
        $this->assertSame(['/about'], $this->urls(array_filter($onAbout, fn(MenuBuilderNode $node) => $node->isActive)));

        $onContact = $this->filter($cache->getOrSet($group, $build), $itemsById, $this->context());
        $active->mark($onContact, '/contact');
        $this->assertSame(['/contact'], $this->urls(array_values(array_filter($onContact, fn(MenuBuilderNode $node) => $node->isActive))));

        // The shared entry itself never carried either page's answer.
        foreach ($cache->getOrSet($group, fn() => $this->fail('The entry must still be a hit.')) as $node) {
            $this->assertFalse($node->isActive, 'Active state must never be written into the cache.');
            $this->assertFalse($node->isActiveAncestor);
        }

        $this->assertSame(1, $builds);
    }

    // =====================================================================
    // Harness
    // =====================================================================

    private int $builds = 0;

    private function service(): TestableCacheService
    {
        $this->builds = 0;

        return new TestableCacheService();
    }

    /**
     * A tree generator that counts how many times it ran — i.e. how many
     * cache misses the test actually produced.
     *
     * @param string[] $urls
     * @return callable():MenuBuilderNode[]
     */
    private function generator(array $urls): callable
    {
        return function() use ($urls) {
            $this->builds++;

            return array_map(fn(string $url, int $index) => $this->node($index + 1, $url), $urls, array_keys($urls));
        };
    }

    

    private function node(int $id, string $url): MenuBuilderNode
    {
        return new MenuBuilderNode(
            id: $id,
            handle: null,
            type: MenuBuilderItem::TYPE_URL,
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
        );
    }

    /** @param array<int,array<string,mixed>> $visibility */
    private function item(int $id, array $visibility = []): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->id = $id;
        $item->visibility = $visibility;

        return $item;
    }

    private function context(
        bool $isLoggedIn = false,
        array $userGroupIds = [],
        int $currentSiteId = self::EN,
        string $now = '2026-08-01 12:00:00',
    ): VisibilityContext {
        $timezone = new DateTimeZone('UTC');

        return new VisibilityContext(
            isLoggedIn: $isLoggedIn,
            userGroupIds: $userGroupIds,
            currentSiteId: $currentSiteId,
            now: new DateTime($now, $timezone),
            environment: 'production',
            timezone: $timezone,
        );
    }

    /**
     * The pipeline step that runs on the cached tree. Non-public (it is only
     * ever called from getTree()), so invoked directly — the same approach
     * MenuBuilderVisibilityTest takes.
     *
     * @param MenuBuilderNode[] $nodes
     * @param array<int,MenuBuilderItem> $itemsById
     * @return MenuBuilderNode[]
     */
    private function filter(array $nodes, array $itemsById, VisibilityContext $context): array
    {
        // The per-request pass reads visibility bags keyed by item ID, not
        // hydrated items — see
        // MenuBuilderItemService::getVisibilityRulesForGroup(). The fixture
        // stays expressed in items and is projected here, keys unchanged.
        $visibilityById = array_map(fn(MenuBuilderItem $item) => $item->visibility, $itemsById);

        $method = new ReflectionMethod(MenuBuilderResolver::class, 'filterVisible');

        return $method->invoke(new MenuBuilderResolver(), $nodes, $visibilityById, new MenuBuilderVisibilityService(), $context);
    }

    /** @param MenuBuilderNode[] $nodes */
    private function urls(array $nodes): array
    {
        return array_values(array_map(fn(MenuBuilderNode $node) => $node->url, $nodes));
    }
}

/**
 * Declared rather than instantiated: a Craft element can't be constructed
 * without a booted app, but the class → source-type mapping is a pure string
 * check and only needs the class to exist.
 */
class SubclassedEntry extends Entry
{
}

/**
 * Stand-ins for a cached payload class before and after a property is added
 * to it — declared rather than mutated, since a class's shape can't change at
 * runtime. See MenuBuilderCacheService::shapeDigest().
 */
class CachedPayloadShapeV1
{
    public ?string $title = null;
}

class CachedPayloadShapeV2
{
    public ?string $title = null;

    public ?string $badge = null;
}

/**
 * MenuBuilderCacheService with its three Craft touchpoints — the cache
 * component, the current site, and `cacheDuration` — supplied by the test,
 * plus a hand-driven stand-in for the DB transaction state. Everything under
 * test (keys, tags, the deferral queue) is the production implementation.
 */
class TestableCacheService extends MenuBuilderCacheService
{
    public ArrayCache $backing;

    public int $siteId = 1;

    public string $schema = '1.0.0';

    public bool $inTransaction = false;

    /** @var callable[] */
    private array $transactionEndHandlers = [];

    public function init(): void
    {
        parent::init();

        // Serializing, like every cache backend Craft ships with — so a read
        // hands back a copy and TagDependency really decides validity.
        $this->backing = new ArrayCache();
    }

    /**
     * Ends the simulated transaction the way Yii does: the outermost
     * commit/rollback fires the events the service registered against.
     */
    public function endTransaction(bool $committed = true): void
    {
        $this->inTransaction = false;

        foreach ($this->transactionEndHandlers as $handler) {
            $handler($committed);
        }
    }

    protected function cache(): \yii\caching\CacheInterface
    {
        return $this->backing;
    }

    protected function currentSiteId(): int
    {
        return $this->siteId;
    }

    protected function schemaVersion(): string
    {
        return $this->schema;
    }

    protected function duration(): ?int
    {
        return null;
    }

    protected function isInTransaction(): bool
    {
        return $this->inTransaction;
    }

    protected function attachTransactionEndHandler(): void
    {
        if ($this->transactionEndHandlers !== []) {
            return;
        }

        $this->transactionEndHandlers[] = function() {
            $this->flushPending();
        };
    }
}

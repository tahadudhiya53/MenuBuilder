<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderElementService;

/**
 * Cache keying and the element-save invalidation that keeps those keys fresh.
 *
 * getOrSet()/invalidateGroup() need a booted Craft::$app and are covered by
 * manual testing; the pure key-building and the decision of *which* groups an
 * element change invalidates are covered here.
 */
class MenuBuilderCacheTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Cache keys: one entry per (handle, site)
    // ---------------------------------------------------------------------

    public function testSameHandleOnDifferentSitesProducesDifferentKeys(): void
    {
        $siteAKey = MenuBuilderCacheService::cacheKey('main', 1);
        $siteBKey = MenuBuilderCacheService::cacheKey('main', 2);

        $this->assertNotSame($siteAKey, $siteBKey);
    }

    public function testSameHandleAndSiteProducesTheSameKey(): void
    {
        $this->assertSame(
            MenuBuilderCacheService::cacheKey('main', 1),
            MenuBuilderCacheService::cacheKey('main', 1)
        );
    }

    public function testDifferentHandlesOnTheSameSiteProduceDifferentKeys(): void
    {
        $mainKey = MenuBuilderCacheService::cacheKey('main', 1);
        $footerKey = MenuBuilderCacheService::cacheKey('footer', 1);

        $this->assertNotSame($mainKey, $footerKey);
    }

    /**
     * Guards against a key scheme where concatenation could collide across
     * different (handle, siteId) pairs, e.g. site 1 + handle "2main" vs.
     * site 12 + handle "main".
     */
    public function testKeysDoNotCollideAcrossSiteAndHandleBoundary(): void
    {
        $this->assertNotSame(
            MenuBuilderCacheService::cacheKey('2main', 1),
            MenuBuilderCacheService::cacheKey('main', 12)
        );
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
}

/**
 * Declared rather than instantiated: a Craft element can't be constructed
 * without a booted app, but the class → source-type mapping is a pure string
 * check and only needs the class to exist.
 */
class SubclassedEntry extends Entry
{
}

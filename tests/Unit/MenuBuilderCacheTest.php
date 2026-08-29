<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderElementService;

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

<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\services\MenuBuilderDynamicNavigationService;

/**
 * Covers MenuBuilderDynamicNavigationService::normalizeConfig() — the
 * clamping/whitelisting the element query is built from. The query itself
 * (site scope, status scope, ordering, limit as applied by Craft) needs a
 * booted app and is manual/integration territory.
 */
class DynamicNavigationConfigTest extends TestCase
{
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

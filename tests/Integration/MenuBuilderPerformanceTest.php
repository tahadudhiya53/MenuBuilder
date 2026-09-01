<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Yii;
use yii\log\Logger;

/**
 * Query budgets for the resolve pipeline.
 *
 * These are regression tests, not benchmarks: they assert *shape* — that the
 * number of queries a menu costs does not grow with the number of items in
 * it — rather than wall-clock times, which vary with the machine and would
 * make the suite flap. The shape is what actually regressed in the past and
 * what silently regresses again the moment something is resolved per node.
 *
 * The counts are deliberately expressed as "the same at 5 items as at 40",
 * with only loose absolute ceilings, so a legitimate extra query somewhere in
 * the pipeline doesn't have to be re-tallied here — but adding one *per item*
 * fails immediately.
 */
class MenuBuilderPerformanceTest extends CraftIntegrationTestCase
{
    /** Entry IDs created for the element-link cases; distinct, so preloading can't be faked by deduplication. */
    private static array $perfEntryIds = [];

    private static bool $perfFixtureLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$perfFixtureLoaded) {
            return;
        }

        for ($i = 0; $i < 40; $i++) {
            self::$perfEntryIds[] = self::createEntry("perf-target-$i", null);
        }

        self::$perfFixtureLoaded = true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db = Craft::$app->getDb();
        $db->enableLogging = true;
        $db->enableProfiling = true;
        Yii::getLogger()->flushInterval = PHP_INT_MAX;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * The number of database round trips $fn makes.
     *
     * Counted from Yii's own profiling messages rather than by wrapping the
     * connection, so it sees every query the pipeline makes including the
     * ones Craft's element queries issue on its behalf.
     */
    private function countQueries(callable $fn): int
    {
        $logger = Yii::getLogger();
        $logger->messages = [];

        $fn();

        $queries = 0;

        // Widened deliberately: yii\log\Logger::$messages is declared as a
        // bare `array`, so static analysis narrows it to an empty one here.
        /** @var list<array{0:mixed,1:int,2:string,3:float}> $messages */
        $messages = $logger->messages;

        foreach ($messages as $message) {
            [, $level, $category] = $message;

            if ($level === Logger::LEVEL_PROFILE_END && in_array($category, ['yii\db\Command::query', 'yii\db\Command::execute'], true)) {
                $queries++;
            }
        }

        $logger->messages = [];

        return $queries;
    }

    /** A menu of $count URL items, all top level. */
    private function urlMenu(string $handle, int $count): MenuBuilderGroup
    {
        $group = self::createMenu($handle, "Perf $handle");

        for ($i = 0; $i < $count; $i++) {
            self::addItem($group, "Item $i", "/$handle-$i");
        }

        return $group;
    }

    /** A menu of $count entry-link items, each pointing at a *different* entry. */
    private function entryMenu(string $handle, int $count): MenuBuilderGroup
    {
        $group = self::createMenu($handle, "Perf $handle");

        for ($i = 0; $i < $count; $i++) {
            $item = new MenuBuilderItem();
            $item->groupId = (int)$group->id;
            $item->title = "Entry link $i";
            $item->type = MenuBuilderItem::TYPE_ENTRY;
            $item->elementId = self::$perfEntryIds[$i];
            $item->enabled = true;

            if (!MenuBuilder::getInstance()->items->save($item)) {
                throw new \RuntimeException("Could not create entry item $i: " . json_encode($item->getErrors()));
            }
        }

        return $group;
    }

    /**
     * The queries one build of $handle costs, from a cold tree cache.
     *
     * The cache invalidation and the menu-list lookup happen *outside* the
     * measured block on purpose. The menu list is one read per request either
     * way (MenuBuilderGroupService memoizes it), so whether populating it
     * lands inside the count is an artefact of which test ran first, not of
     * the pipeline being measured.
     */
    private function coldResolveQueries(MenuBuilderGroup $group, string $handle): int
    {
        MenuBuilder::getInstance()->cache->invalidateGroupId((int)$group->id);
        MenuBuilder::getInstance()->groups->getByHandle($handle);

        return $this->countQueries(fn() => MenuBuilder::getInstance()->resolver->getTree($handle, '/'));
    }

    // ---------------------------------------------------------------------
    // Cache miss: the tree is built once, not per item
    // ---------------------------------------------------------------------

    public function testBuildingAUrlMenuCostsTheSameNumberOfQueriesAtAnySize(): void
    {
        $small = $this->urlMenu('perfUrlSmall', 5);
        $large = $this->urlMenu('perfUrlLarge', 60);

        $smallQueries = $this->coldResolveQueries($small, 'perfUrlSmall');
        $largeQueries = $this->coldResolveQueries($large, 'perfUrlLarge');

        $this->assertSame(
            $smallQueries,
            $largeQueries,
            'A URL menu must cost a fixed number of queries — 12x the items must not mean more queries.'
        );
        $this->assertLessThanOrEqual(3, $largeQueries, 'Building a menu should be a small, fixed number of reads.');
    }

    /**
     * The N+1 this suite exists to keep dead: entry/category/asset links were
     * resolved with one element query *each*, so a 100-item menu cost 100
     * queries to build. They are preloaded in one query per element type now
     * ({@see \Tahadudhiya\MenuBuilder\linktypes\PreloadingLinkTypeResolverInterface}).
     */
    public function testEntryLinksArePreloadedRatherThanQueriedPerItem(): void
    {
        $small = $this->entryMenu('perfEntrySmall', 5);
        $large = $this->entryMenu('perfEntryLarge', 40);

        $smallQueries = $this->coldResolveQueries($small, 'perfEntrySmall');
        $largeQueries = $this->coldResolveQueries($large, 'perfEntryLarge');

        $this->assertSame(
            $smallQueries,
            $largeQueries,
            'Element links must be batch-loaded: 8x the items must not mean 8x the queries.'
        );
        $this->assertLessThan(
            40,
            $largeQueries,
            'A 40-item entry menu costing 40+ queries means the element N+1 is back.'
        );
    }

    public function testAPreloadedEntryResolvesToTheSameLinkAsAnUnpreloadedOne(): void
    {
        // Correctness half of the batching: the preloaded element must be the
        // element the per-item query would have found. Resolved through the
        // full pipeline (preloaded) and directly through the link resolver
        // with nothing preloaded, and compared.
        $group = $this->entryMenu('perfEntryParity', 5);
        $tree = MenuBuilder::getInstance()->resolver->getTree('perfEntryParity', '/');

        $this->assertNotNull($tree);
        $nodes = $tree->items;
        $this->assertCount(5, $nodes);

        $items = MenuBuilder::getInstance()->items->getFlatForGroup((int)$group->id);

        foreach ($items as $item) {
            $direct = MenuBuilder::getInstance()->linkResolver->resolve($item);
            $node = null;

            foreach ($nodes as $candidate) {
                if ($candidate->id === $item->id) {
                    $node = $candidate;
                }
            }

            $this->assertNotNull($node, "Item {$item->id} is missing from the resolved tree.");
            $this->assertSame($direct->url, $node->url, 'A preloaded element must resolve to the same URL.');
            $this->assertSame($direct->isAvailable, $node->isLinkAvailable, 'A preloaded element must resolve to the same availability.');
        }
    }

    // ---------------------------------------------------------------------
    // Cache hit: the per-request cost of an already-built menu
    // ---------------------------------------------------------------------

    /**
     * A cache hit re-checks visibility against the current rows, which is one
     * read — and must stay one read, of two columns, however big the menu is.
     * Hydrating a MenuBuilderItem per row here was 79% of the cost of every
     * cached request.
     */
    public function testAWarmMenuCostsOneQueryRegardlessOfSize(): void
    {
        $small = $this->urlMenu('perfWarmSmall', 5);
        $large = $this->urlMenu('perfWarmLarge', 60);

        // Warm both, so neither count includes the build.
        MenuBuilder::getInstance()->resolver->getTree('perfWarmSmall', '/');
        MenuBuilder::getInstance()->resolver->getTree('perfWarmLarge', '/');

        $smallQueries = $this->countQueries(fn() => MenuBuilder::getInstance()->resolver->getTree('perfWarmSmall', '/'));
        $largeQueries = $this->countQueries(fn() => MenuBuilder::getInstance()->resolver->getTree('perfWarmLarge', '/'));

        $this->assertSame(1, $smallQueries, 'A cache hit should cost exactly one query: the visibility re-read.');
        $this->assertSame(1, $largeQueries, 'A cache hit must not scale with item count.');

        // Unused locals would otherwise read as an incomplete fixture.
        $this->assertNotNull($small->id);
        $this->assertNotNull($large->id);
    }

    public function testAWarmEntryMenuDoesNotRequeryItsElements(): void
    {
        $this->entryMenu('perfWarmEntry', 40);
        MenuBuilder::getInstance()->resolver->getTree('perfWarmEntry', '/');

        $queries = $this->countQueries(fn() => MenuBuilder::getInstance()->resolver->getTree('perfWarmEntry', '/'));

        $this->assertSame(
            1,
            $queries,
            'Resolved element URLs live in the cached payload; a warm request must not touch the elements table.'
        );
    }

    // ---------------------------------------------------------------------
    // Write path
    // ---------------------------------------------------------------------

    /**
     * Saving an item used to read its menu twice from the database — once for
     * its custom field definitions, once for its maxDepth — so a bulk edit
     * cost two menu queries per item on top of its own writes.
     */
    public function testSavingManyItemsDoesNotRereadTheMenuPerItem(): void
    {
        $group = $this->urlMenu('perfBulk', 20);
        $items = MenuBuilder::getInstance()->items->getFlatForGroup((int)$group->id);

        $queries = $this->countQueries(function() use ($items) {
            foreach ($items as $item) {
                $item->title .= '!';
                MenuBuilder::getInstance()->items->save($item);
            }
        });

        $this->assertLessThanOrEqual(
            count($items) * 4,
            $queries,
            'Saving an item should cost a handful of queries; re-reading the menu per item is a regression.'
        );
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\services\MenuBuilderActiveResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;
use Tahadudhiya\MenuBuilder\services\MenuBuilderResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderVisibilityService;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;
use yii\caching\ArrayCache;

/**
 * The cache **behaving**, not just keying: hit, miss, targeted invalidation,
 * staleness, transaction safety, and the boundary that lets two users share
 * one entry.
 *
 * These run against a real Yii cache backend (`ArrayCache`, which serializes
 * exactly like Craft's file/DB/Redis caches do, so a read really does hand
 * back a fresh object graph and `TagDependency` really does decide validity).
 * The three things MenuBuilderCacheService needs a booted Craft app for — the
 * cache component, the current site, and `cacheDuration` — are the only
 * things stubbed, by overriding the seams the service exposes for exactly
 * that purpose. Everything else is production code: the key scheme, the tags,
 * the deferral queue, the pipeline steps that run around the cache.
 *
 * The invariants under test:
 *
 * 1. A hit serves the cached tree; a miss builds it once and stores it.
 * 2. Invalidating one menu leaves every other menu's cache intact, on every
 *    site — no change to one menu ever flushes the install.
 * 3. Site A never reads site B's tree.
 * 4. Nothing user-specific, date-specific or request-specific is in the entry,
 *    so two users, two days and two URLs safely share it.
 * 5. An invalidation raised inside a transaction lands *after* the commit, so
 *    a concurrent request can't re-cache pre-commit data and have it survive.
 */
class MenuBuilderCacheIntegrationTest extends TestCase
{
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

    /**
     * The real service hangs the flush off Yii's own transaction events,
     * which fire only for the **outermost** commit/rollback — so a nested
     * bulk operation's savepoint release can't flush the queue early. Read
     * from the source, since the events themselves need a booted app.
     */
    public function testTheFlushIsHungOffTheOutermostTransactionsEvents(): void
    {
        $source = $this->methodSource(MenuBuilderCacheService::class, 'attachTransactionEndHandler');

        $this->assertStringContainsString('EVENT_COMMIT_TRANSACTION', $source);
        $this->assertStringContainsString('EVENT_ROLLBACK_TRANSACTION', $source);
        $this->assertStringContainsString('flushPending()', $source);
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

    private function group(int $id = 1, string $handle = 'main', ?string $dateUpdated = '2026-08-01 09:00:00'): MenuBuilderGroup
    {
        $group = new MenuBuilderGroup();
        $group->id = $id;
        $group->name = ucfirst($handle);
        $group->handle = $handle;
        $group->dateUpdated = $dateUpdated;

        return $group;
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
        $method = new ReflectionMethod(MenuBuilderResolver::class, 'filterVisible');

        return $method->invoke(new MenuBuilderResolver(), $nodes, $itemsById, new MenuBuilderVisibilityService(), $context);
    }

    /** @param MenuBuilderNode[] $nodes */
    private function urls(array $nodes): array
    {
        return array_values(array_map(fn(MenuBuilderNode $node) => $node->url, $nodes));
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file((string)(new ReflectionClass($class))->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
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

<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use ReflectionClass;
use ReflectionProperty;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use yii\caching\CacheInterface;
use yii\caching\TagDependency;
use yii\db\Connection;

/**
 * Caches the link-resolved (but not yet visibility-filtered or active-state
 * marked — those are per-request/per-user and must never be cached) tree per
 * menu, per site.
 *
 * ## What is cached, and what can never be
 *
 * The cached payload is `MenuBuilderNode[]`: resolved URLs, titles,
 * availability, attributes, and any synthesised dynamic children. It is a
 * function of (menu, site, database state) and of nothing about the current
 * visitor or the current request. Visibility filtering (the current user,
 * the current date, the environment) and active-state marking (the current
 * URI) run *after* the cache read, on copies
 * ({@see MenuBuilderNode::withChildren()}), so one entry safely serves an
 * anonymous visitor and a logged-in one, yesterday and today, and every URL
 * on the site. See MenuBuilderResolver::getTree() for the pipeline order and
 * MenuBuilderVisibilityTest for the tests that pin it.
 *
 * ## Keys
 *
 * `menu-builder:tree:{siteId}:{handle}:{configVersion}` — see
 * {@see cacheKey()}. Three things are in the key because three things can
 * make two payloads differ:
 *
 * - **site**, because `ElementLinkResolver` resolves elements against the
 *   current site: the same menu legitimately resolves to different URLs,
 *   titles and availability per site (`menubuilder_groups.handle` is unique
 *   per install, not per site, so the menu itself isn't site-scoped — its
 *   resolved tree is).
 * - **menu**, by handle — the identity Twig asks for.
 * - **configuration/version** ({@see configVersion()}), so that a menu whose
 *   configuration changed, or a plugin upgrade that changed the shape of the
 *   cached payload itself, reads a *different* key rather than an entry
 *   built under the old one.
 *
 * ## Invalidation
 *
 * Every entry is tagged twice: with a per-menu tag
 * ({@see groupTag()}) and with the global {@see CACHE_TAG}. Targeted
 * invalidation is therefore one tag invalidation for the affected menu — it
 * reaches that menu's entry on *every* site and under *every* past config
 * version, without enumerating site IDs (which Craft answers differently
 * depending on where it is called from) and without knowing which config
 * versions were ever written. `invalidateAll()` (the global tag) is reserved
 * for changes that genuinely affect every menu — a site save or delete.
 *
 * Callers: MenuBuilderItemService (item writes), MenuBuilderGroupService
 * (menu writes), MenuBuilderElementService (entry/category/asset and
 * container/site changes).
 *
 * ## Transactions
 *
 * Invalidating *inside* an open transaction would be a stale-cache hazard: a
 * concurrent front-end request could rebuild and re-cache the tree from
 * pre-commit data, and nothing would invalidate it again after the commit.
 * Bulk operations do exactly that — they wrap many single-item saves and
 * deletes, each of which invalidates. So invalidation raised while a
 * transaction is open is queued and flushed when the outermost transaction
 * ends ({@see flushPending()}), on rollback as well as commit (a rebuild is
 * always safe; a stale entry is not).
 *
 * Entries are written with Craft's `cacheDuration` as a ceiling rather than
 * no expiry at all — see {@see duration()} for why (time-based entry status
 * transitions fire no event to invalidate on).
 */
class MenuBuilderCacheService extends Component
{
    private const CACHE_TAG = 'menu-builder';
    private const GROUP_TAG_PREFIX = 'menu-builder:group:';
    private const KEY_PREFIX = 'menu-builder:tree:';

    /**
     * The classes whose shape the cached payload *is*. A cache entry is
     * serialized object graph, so adding, removing or renaming a property on
     * any of them makes previously written entries the wrong shape — and
     * unserializing one would hand Twig an object with uninitialized
     * readonly properties. Their property lists are hashed into the key
     * ({@see payloadVersion()}) so a plugin upgrade that changes the payload
     * reads a new key instead, with no migration and nothing to remember to
     * bump by hand.
     */
    public const PAYLOAD_CLASSES = [MenuBuilderNode::class, MenuBuilderMegaMenuConfig::class];

    /** @var int[] Menu IDs whose invalidation is waiting for a transaction to end. */
    private array $pendingGroupIds = [];

    private bool $pendingAll = false;

    private bool $transactionHandlerAttached = false;

    /**
     * @param callable():array<int,MenuBuilderNode> $generator
     * @return MenuBuilderNode[]
     */
    public function getOrSet(MenuBuilderGroup $group, callable $generator): array
    {
        // An unsaved menu has no identity to key or tag by, so it is
        // resolved fresh rather than cached under something guessable.
        if ($group->id === null) {
            return $generator();
        }

        $cache = $this->cache();
        $key = self::cacheKey($group->handle, $this->currentSiteId(), $this->configVersionFor($group));
        $cached = $cache->get($key);

        // A miss is `false`; anything else must still be the payload shape
        // this version writes, so a foreign or corrupted value rebuilds
        // instead of reaching Twig.
        if (is_array($cached)) {
            return $cached;
        }

        $tree = $generator();
        $cache->set(
            $key,
            $tree,
            $this->duration(),
            new TagDependency(['tags' => [self::CACHE_TAG, self::groupTag((int)$group->id)]])
        );

        return $tree;
    }

    /**
     * Invalidates one menu's resolved tree — on every site, and under every
     * config version it was ever cached under, because the per-menu tag is
     * on all of them (see the class docblock). Nothing else is touched: a
     * change to one menu never flushes another's cache.
     */
    public function invalidateGroupId(int $groupId): void
    {
        $this->invalidateGroupIds([$groupId]);
    }

    /**
     * @param int[] $groupIds
     */
    public function invalidateGroupIds(array $groupIds): void
    {
        $groupIds = self::normalizeGroupIds($groupIds);

        if ($groupIds === []) {
            return;
        }

        if ($this->deferUntilTransactionEnds()) {
            $this->pendingGroupIds = self::normalizeGroupIds(array_merge($this->pendingGroupIds, $groupIds));

            return;
        }

        $this->invalidateTags(array_map(fn(int $groupId) => self::groupTag($groupId), $groupIds));
    }

    /**
     * Handle-keyed entry point, kept because a handle is what third-party
     * code integrating with a menu knows (see ARCHITECTURE.md, "Extension
     * points" — a custom link type has to invalidate its own menus).
     * Internally invalidation is by menu **ID**, which a rename can't
     * invalidate out from under.
     */
    public function invalidateGroup(string $groupHandle): void
    {
        $this->invalidateGroups([$groupHandle]);
    }

    /**
     * @param string[] $groupHandles
     */
    public function invalidateGroups(array $groupHandles): void
    {
        $groupIds = [];

        foreach (array_unique($groupHandles) as $groupHandle) {
            $groupId = MenuBuilder::getInstance()->groups->getByHandle($groupHandle)?->id;

            if ($groupId !== null) {
                $groupIds[] = $groupId;
            }
        }

        $this->invalidateGroupIds($groupIds);
    }

    /**
     * Every cached tree, on every site. Reserved for changes that really do
     * affect every menu — a site save or delete (see
     * MenuBuilderElementService::handleSiteChange()). A change to one menu
     * must never come through here.
     */
    public function invalidateAll(): void
    {
        if ($this->deferUntilTransactionEnds()) {
            $this->pendingAll = true;

            return;
        }

        $this->invalidateTags([self::CACHE_TAG]);
    }

    /**
     * Runs the invalidations that were queued while a transaction was open.
     * Registered on both commit and rollback: after a commit the queued
     * change is live and the cache must go; after a rollback nothing
     * changed, but a concurrent request may have re-cached mid-transaction
     * data in the meantime, and an unnecessary rebuild is the safe error.
     */
    public function flushPending(): void
    {
        $groupIds = $this->pendingGroupIds;
        $all = $this->pendingAll;

        $this->pendingGroupIds = [];
        $this->pendingAll = false;

        if ($all) {
            $this->invalidateTags([self::CACHE_TAG]);

            return;
        }

        if ($groupIds !== []) {
            $this->invalidateTags(array_map(fn(int $groupId) => self::groupTag($groupId), $groupIds));
        }
    }

    /**
     * Whether there are queued invalidations still waiting for a transaction
     * to end. Diagnostic: the invariant is that this is false once no
     * transaction is open.
     */
    public function hasPendingInvalidations(): bool
    {
        return $this->pendingAll || $this->pendingGroupIds !== [];
    }

    /**
     * Entry/category/asset lifecycle events cover every *edited* change, but
     * two status transitions happen on a clock with no event at all: a
     * pending entry going live when its `postDate` arrives, and a live entry
     * expiring at its `expiryDate`. With a never-expiring cache entry those
     * would be invisible to navigation indefinitely, so the resolved tree is
     * written with Craft's own `cacheDuration` as a ceiling — the same bound
     * Craft puts on its element query caches. Explicit invalidation is still
     * what normally refreshes a tree; this only limits how long an
     * event-less change can go unnoticed.
     */
    protected function duration(): ?int
    {
        return self::resolveDuration(Craft::$app->getConfig()->getGeneral()->cacheDuration);
    }

    /**
     * `cacheDuration` is normalized to an int number of seconds by
     * GeneralConfig, where 0 (or a negative/non-numeric value) means "no
     * expiry" — Yii spells that as null. Pure + static so the mapping is
     * unit-testable without a booted Craft app.
     */
    public static function resolveDuration(mixed $configured): ?int
    {
        if (!is_numeric($configured) || (int)$configured <= 0) {
            return null;
        }

        return (int)$configured;
    }

    /**
     * The three things that make two resolved trees different, in one key:
     * the site, the menu, and the configuration/payload version they were
     * built under. Pure + static so key construction is unit-testable
     * without a booted Craft app.
     *
     * The separator is a character a handle can't contain
     * (MenuBuilderGroup's handle pattern is `[a-zA-Z][a-zA-Z0-9_]*`), so no
     * two different (handle, site, version) triples can spell the same key.
     */
    public static function cacheKey(string $groupHandle, int $siteId, string $configVersion): string
    {
        return self::KEY_PREFIX . $siteId . ':' . $groupHandle . ':' . $configVersion;
    }

    /**
     * The per-menu invalidation tag. Keyed by menu **ID**, not handle: a
     * rename changes the handle (and so the cache key), and invalidation
     * must still reach the entries written before it.
     */
    public static function groupTag(int $groupId): string
    {
        return self::GROUP_TAG_PREFIX . $groupId;
    }

    /**
     * The "relevant configuration/version" half of the cache key: a short
     * digest of everything outside the item rows that the cached payload
     * depends on.
     *
     * - the payload shape ({@see payloadVersion()}) and the plugin's schema
     *   version, so an upgrade never reads an entry written by an older
     *   shape of the code;
     * - the menu's own identity and configuration — `id` (a handle can be
     *   freed and reused by a different menu), `handle`, and `dateUpdated`,
     *   which the database moves on **every** menu save. That last one means
     *   editing a menu produces a fresh key by construction, independently
     *   of the invalidation that also runs.
     *
     * Pure + static, and takes the version rather than reading the plugin
     * instance, so it is unit-testable without a booted Craft app.
     */
    public static function configVersion(MenuBuilderGroup $group, string $schemaVersion): string
    {
        return substr(sha1(implode("\0", [
            self::payloadVersion(),
            $schemaVersion,
            (string)($group->id ?? ''),
            $group->handle,
            (string)($group->dateUpdated ?? ''),
        ])), 0, 12);
    }

    /**
     * A digest of the property lists of the classes the cached payload is
     * made of ({@see PAYLOAD_CLASSES}). Memoized: one reflection pass per
     * request at most, and only on a cache read.
     */
    public static function payloadVersion(): string
    {
        static $version = null;

        return $version ??= self::shapeDigest(self::PAYLOAD_CLASSES);
    }

    /**
     * A short digest of the given classes' declared property names, in
     * declaration order. Pure + static (the class list is passed in) so what
     * "the payload changed shape" means is unit-testable, the same reasoning
     * as {@see resolveDuration()}.
     *
     * @param class-string[] $classes
     */
    public static function shapeDigest(array $classes): string
    {
        $shapes = array_map(
            fn(string $class) => $class . '(' . implode(',', array_map(
                fn(ReflectionProperty $property) => $property->getName(),
                (new ReflectionClass($class))->getProperties()
            )) . ')',
            $classes
        );

        return substr(sha1(implode('|', $shapes)), 0, 8);
    }

    /**
     * @param int[] $groupIds
     * @return int[]
     */
    private static function normalizeGroupIds(array $groupIds): array
    {
        $groupIds = array_map('intval', $groupIds);

        return array_values(array_unique(array_filter($groupIds, fn(int $groupId) => $groupId > 0)));
    }

    private function configVersionFor(MenuBuilderGroup $group): string
    {
        return self::configVersion($group, $this->schemaVersion());
    }

    /**
     * @param string[] $tags
     */
    private function invalidateTags(array $tags): void
    {
        TagDependency::invalidate($this->cache(), $tags);
    }

    /**
     * True when the invalidation must wait for the current transaction to
     * end (and has been queued), false when it can run now. See the
     * "Transactions" section of the class docblock.
     */
    private function deferUntilTransactionEnds(): bool
    {
        if (!$this->isInTransaction()) {
            return false;
        }

        $this->attachTransactionEndHandler();

        return true;
    }

    protected function isInTransaction(): bool
    {
        return Craft::$app->getDb()->getTransaction() !== null;
    }

    /**
     * Yii fires these two events only when the **outermost** transaction
     * ends (`yii\db\Transaction::commit()`/`rollBack()` trigger them at
     * level 0), which is exactly the boundary the queue has to wait for —
     * a nested bulk operation's savepoint release must not flush early.
     * Attached at most once per request; the handler is a no-op when
     * nothing is queued.
     */
    protected function attachTransactionEndHandler(): void
    {
        if ($this->transactionHandlerAttached) {
            return;
        }

        $this->transactionHandlerAttached = true;

        $db = Craft::$app->getDb();
        $handler = function() {
            $this->flushPending();
        };

        $db->on(Connection::EVENT_COMMIT_TRANSACTION, $handler);
        $db->on(Connection::EVENT_ROLLBACK_TRANSACTION, $handler);
    }

    protected function cache(): CacheInterface
    {
        return Craft::$app->getCache();
    }

    protected function currentSiteId(): int
    {
        return Craft::$app->getSites()->getCurrentSite()->id;
    }

    protected function schemaVersion(): string
    {
        return MenuBuilder::getInstance()->schemaVersion;
    }
}

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
 * Caches the link-resolved (but not yet visibility-filtered or active-state marked — those are
 * per-request/per-user and must never be cached) tree per menu, per site.
*/
class MenuBuilderCacheService extends Component
{
    private const CACHE_TAG = 'menu-builder';
    private const GROUP_TAG_PREFIX = 'menu-builder:group:';
    private const KEY_PREFIX = 'menu-builder:tree:';

    /**
     * The classes whose shape the cached payload *is*.
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
        // An unsaved menu has no identity to key or tag by, so it is resolved fresh rather than
        // cached under something guessable.
        if ($group->id === null) {
            return $generator();
        }

        $cache = $this->cache();
        $key = self::cacheKey($group->handle, $this->currentSiteId(), $this->configVersionFor($group));
        $cached = $cache->get($key);

        // A miss is `false`; anything else must still be the payload shape this version writes, so
        // a foreign or corrupted value rebuilds instead of reaching Twig.
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
     * Invalidates one menu's resolved tree — on every site, and under every config version it was
     * ever cached under, because the per-menu tag is on all of them (see the class docblock).
    */
    public function invalidateGroupId(int $groupId): void
    {
        $this->invalidateGroupIds([$groupId]);
    }

    /** @param int[] $groupIds */
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
     * Handle-keyed entry point, kept because a handle is what third-party code integrating with a
     * menu knows (see ARCHITECTURE.md, "Extension points" — a custom link type has to invalidate
     * its own menus).
    */
    public function invalidateGroup(string $groupHandle): void
    {
        $this->invalidateGroups([$groupHandle]);
    }

    /** @param string[] $groupHandles */
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
     * Every cached tree, on every site.
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
     * Whether there are queued invalidations still waiting for a transaction to end.
    */
    public function hasPendingInvalidations(): bool
    {
        return $this->pendingAll || $this->pendingGroupIds !== [];
    }

    /**
     * Entry/category/asset lifecycle events cover every *edited* change, but two status transitions
     * happen on a clock with no event at all: a pending entry going live when its `postDate`
     * arrives, and a live entry expiring at its `expiryDate`.
    */
    protected function duration(): ?int
    {
        return self::resolveDuration(Craft::$app->getConfig()->getGeneral()->cacheDuration);
    }

    /**
     * `cacheDuration` is normalized to an int number of seconds by GeneralConfig, where 0 (or a
     * negative/non-numeric value) means "no expiry" — Yii spells that as null.
    */
    public static function resolveDuration(mixed $configured): ?int
    {
        if (!is_numeric($configured) || (int)$configured <= 0) {
            return null;
        }

        return (int)$configured;
    }

    /**
     * The three things that make two resolved trees different, in one key: the site, the menu, and
     * the configuration/payload version they were built under.
    */
    public static function cacheKey(string $groupHandle, int $siteId, string $configVersion): string
    {
        return self::KEY_PREFIX . $siteId . ':' . $groupHandle . ':' . $configVersion;
    }

    /**
     * The per-menu invalidation tag.
    */
    public static function groupTag(int $groupId): string
    {
        return self::GROUP_TAG_PREFIX . $groupId;
    }

    /**
     * The "relevant configuration/version" half of the cache key: a short digest of everything
     * outside the item rows that the cached payload depends on.
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
     * A digest of the property lists of the classes the cached payload is made of ({@see
     * PAYLOAD_CLASSES}).
    */
    public static function payloadVersion(): string
    {
        static $version = null;

        return $version ??= self::shapeDigest(self::PAYLOAD_CLASSES);
    }

    /**
     * A short digest of the given classes' declared property names, in declaration order.
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

    /** @param string[] $tags */
    private function invalidateTags(array $tags): void
    {
        TagDependency::invalidate($this->cache(), $tags);
    }

    /**
     * True when the invalidation must wait for the current transaction to end (and has been
     * queued), false when it can run now.
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
     * Yii fires these two events only when the **outermost** transaction ends
     * (`yii\db\Transaction::commit()`/`rollBack()` trigger them at level 0), which is exactly the
     * boundary the queue has to wait for — a nested bulk operation's savepoint release must not
     * flush early.
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

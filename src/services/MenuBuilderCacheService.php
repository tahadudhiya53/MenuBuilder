<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use yii\caching\TagDependency;

/**
 * Caches the link-resolved (but not yet visibility-filtered or active-state
 * marked — those are per-request/per-user and must never be cached) tree per
 * group. Invalidation is targeted: a navigation item/group change invalidates
 * only its own group (see MenuBuilderItemService/MenuBuilderGroupService),
 * and an entry/category/asset save/delete/restore invalidates only the
 * groups whose items actually reference that element (see
 * MenuBuilderElementService), found via a single indexed query rather than a
 * blind flush of every cached tree. `invalidateAll()` remains available for
 * changes that are genuinely global (e.g. group-level settings that could
 * affect rendering of every menu) but is no longer used as a catch-all for
 * element changes.
 *
 * Cache keys are site-aware: `menubuilder_groups.handle` is unique
 * per-install, not per-site (see the Install migration), so a group itself
 * isn't scoped to a site — but the *resolved* tree is, because
 * ElementLinkResolver queries entries/categories/assets scoped to
 * `Craft::$app->getSites()->getCurrentSite()`, and the same element can
 * resolve to a different URL, title, or availability on each site. Without
 * the site in the key, two sites rendering the same group handle would read
 * and overwrite each other's resolved tree.
 *
 * Entries are written with Craft's `cacheDuration` as a ceiling rather than
 * no expiry at all — see {@see duration()} for why (time-based entry status
 * transitions fire no event to invalidate on).
 */
class MenuBuilderCacheService extends Component
{
    private const CACHE_TAG = 'menu-builder';

    /**
     * @param callable():array<int,MenuBuilderNode> $generator
     * @return MenuBuilderNode[]
     */
    public function getOrSet(string $groupHandle, callable $generator): array
    {
        $cache = Craft::$app->getCache();
        $key = self::cacheKey($groupHandle, $this->currentSiteId());
        $cached = $cache->get($key);

        if ($cached !== false) {
            return $cached;
        }

        $tree = $generator();
        $cache->set($key, $tree, $this->duration(), new TagDependency(['tags' => [self::CACHE_TAG]]));

        return $tree;
    }

    /**
     * Invalidates this group's resolved tree on every site — a group isn't
     * itself site-scoped (see class docblock), and callers here (item/group
     * saves, element lifecycle events) don't know which sites' resolved
     * trees the change actually affected, so every site's cache entry for
     * this group handle is cleared rather than risking a stale one surviving.
     */
    public function invalidateGroup(string $groupHandle): void
    {
        $cache = Craft::$app->getCache();

        foreach ($this->allSiteIds() as $siteId) {
            $cache->delete(self::cacheKey($groupHandle, $siteId));
        }
    }

    /**
     * @param string[] $groupHandles
     */
    public function invalidateGroups(array $groupHandles): void
    {
        foreach (array_unique($groupHandles) as $groupHandle) {
            $this->invalidateGroup($groupHandle);
        }
    }

    public function invalidateAll(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
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
    private function duration(): ?int
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

    public static function cacheKey(string $groupHandle, int $siteId): string
    {
        return 'menu-builder:tree:' . $siteId . ':' . $groupHandle;
    }

    private function currentSiteId(): int
    {
        return Craft::$app->getSites()->getCurrentSite()->id;
    }

    /**
     * @return int[]
     */
    private function allSiteIds(): array
    {
        return Craft::$app->getSites()->getAllSiteIds();
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use yii\caching\TagDependency;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;

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
 */
class MenuBuilderCacheService extends Component
{
    private const CACHE_TAG = 'menu-builder';
    private const DURATION = null; // cache until explicitly invalidated

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
        $cache->set($key, $tree, self::DURATION, new TagDependency(['tags' => [self::CACHE_TAG]]));

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

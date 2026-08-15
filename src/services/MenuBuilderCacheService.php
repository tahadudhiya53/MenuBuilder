<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use yii\caching\TagDependency;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * Caches the link-resolved (but not yet visibility-filtered or active-state
 * marked — those are per-request/per-user and must never be cached) tree per
 * group. Deliberately coarse invalidation: any navigation item/group change,
 * or any entry/category/asset save/delete, flushes every cached tree. This is
 * a documented tradeoff over a fragile per-item reverse-index — trees are
 * cheap to rebuild, and coarse invalidation can never leave stale navigation
 * after an editor change (see plan §"Caching").
 */
class MenuBuilderCacheService extends Component
{
    private const CACHE_TAG = 'menu-builder';
    private const DURATION = null; // cache until explicitly invalidated

    /**
     * @param callable():array<int,MenuBuilderItem> $generator
     * @return MenuBuilderItem[]
     */
    public function getOrSet(string $groupHandle, callable $generator): array
    {
        $cache = Craft::$app->getCache();
        $key = $this->cacheKey($groupHandle);
        $cached = $cache->get($key);

        if ($cached !== false) {
            return $cached;
        }

        $tree = $generator();
        $cache->set($key, $tree, self::DURATION, new TagDependency(['tags' => [self::CACHE_TAG]]));

        return $tree;
    }

    public function invalidateGroup(string $groupHandle): void
    {
        Craft::$app->getCache()->delete($this->cacheKey($groupHandle));
    }

    public function invalidateAll(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    private function cacheKey(string $groupHandle): string
    {
        return 'menu-builder:tree:' . $groupHandle;
    }
}

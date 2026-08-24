<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;

/**
 * getOrSet()/invalidateGroup() need a booted Craft::$app (cache service,
 * site service) and are exercised via manual/integration testing instead
 * (see tests/bootstrap.php). The pure key-building logic they both rely on
 * — the part that must actually be site-aware — is covered here.
 */
class MenuBuilderCacheServiceTest extends TestCase
{
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
}

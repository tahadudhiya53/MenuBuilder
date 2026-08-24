<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\services\MenuBuilderVisibilityService;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

/**
 * Covers MenuBuilderVisibilityService::isVisible() — the AND-combination
 * across an item's configured rules and the fail-closed behavior for an
 * unrecognized rule type — which VisibilityRulesTest deliberately leaves
 * untested since it only exercises individual VisibilityRuleInterface
 * implementations. Built without a booted Craft app, same as the rest of
 * this suite (see tests/bootstrap.php).
 */
class MenuBuilderVisibilityServiceTest extends TestCase
{
    private function context(bool $isLoggedIn = true, array $userGroupIds = [], ?int $currentSiteId = 1): VisibilityContext
    {
        $timezone = new DateTimeZone('UTC');

        return new VisibilityContext(
            isLoggedIn: $isLoggedIn,
            userGroupIds: $userGroupIds,
            currentSiteId: $currentSiteId,
            now: new DateTime('now', $timezone),
            environment: 'production',
            timezone: $timezone,
        );
    }

    private function item(array $visibility): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->visibility = $visibility;

        return $item;
    }

    public function testNoRulesIsAlwaysVisible(): void
    {
        $service = new MenuBuilderVisibilityService();

        $this->assertTrue($service->isVisible($this->item([]), $this->context()));
    }

    public function testAllRulesMustPass(): void
    {
        $service = new MenuBuilderVisibilityService();

        $item = $this->item([
            ['type' => 'loggedIn'],
            ['type' => 'userGroup', 'groupIds' => [5]],
        ]);

        $this->assertTrue($service->isVisible($item, $this->context(isLoggedIn: true, userGroupIds: [5])), 'Both rules pass.');
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: true, userGroupIds: [9])), 'userGroup rule fails, so the whole item is hidden.');
        $this->assertFalse($service->isVisible($item, $this->context(isLoggedIn: false)), 'loggedIn rule fails, so the whole item is hidden.');
    }

    public function testUnknownRuleTypeFailsClosed(): void
    {
        $service = new MenuBuilderVisibilityService();

        $item = $this->item([
            ['type' => 'always'],
            ['type' => 'somethingThatWasNeverRegistered'],
        ]);

        $this->assertFalse($service->isVisible($item, $this->context()), 'An unrecognized rule type must hide the item rather than being ignored.');
    }

    public function testMalformedRuleWithoutTypeFailsClosed(): void
    {
        $service = new MenuBuilderVisibilityService();

        $item = $this->item([
            ['groupIds' => [1, 2]], // missing "type"
        ]);

        $this->assertFalse($service->isVisible($item, $this->context()));
    }
}

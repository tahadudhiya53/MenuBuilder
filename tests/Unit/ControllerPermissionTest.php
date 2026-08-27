<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\controllers\BaseMenuBuilderController;
use Tahadudhiya\MenuBuilder\controllers\DashboardController;
use Tahadudhiya\MenuBuilder\controllers\GroupsController;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;
use Tahadudhiya\MenuBuilder\services\MenuBuilderItemService;

/**
 * Server-side authorization for every control-panel action.
 *
 * The permission decision is pure static logic (requiredPermissionForAction())
 * deliberately factored out of beforeAction() so it can be checked without a
 * booted Craft app or request. The CP hides buttons a user cannot use; these
 * mappings are what a direct POST is actually checked against.
 */
class ControllerPermissionTest extends TestCase
{
    // ---------------------------------------------------------------------
    // One shared permission gate, inherited by every controller
    // ---------------------------------------------------------------------

    /**
     * @return array<string,array{class-string}>
     */
    public static function controllerProvider(): array
    {
        return [
            'groups' => [GroupsController::class],
            'items' => [ItemsController::class],
            'dashboard' => [DashboardController::class],
        ];
    }

    /**
     * @dataProvider controllerProvider
     * @param class-string $controllerClass
     */
    public function testEveryControllerInheritsTheSharedGate(string $controllerClass): void
    {
        $this->assertTrue(
            is_subclass_of($controllerClass, BaseMenuBuilderController::class),
            "$controllerClass must route its permission check through the shared base controller."
        );
    }

    /**
     * @dataProvider controllerProvider
     * @param class-string $controllerClass
     */
    public function testNoControllerDeclaresItsOwnBeforeAction(string $controllerClass): void
    {
        $declaringClass = (new \ReflectionMethod($controllerClass, 'beforeAction'))->getDeclaringClass()->getName();

        $this->assertSame(
            BaseMenuBuilderController::class,
            $declaringClass,
            "$controllerClass must not re-implement the permission gate."
        );
    }

    /**
     * @dataProvider controllerProvider
     * @param class-string $controllerClass
     */
    public function testEveryControllerDeclaresWhichPermissionAnActionNeeds(string $controllerClass): void
    {
        $declaringClass = (new \ReflectionMethod($controllerClass, 'requiredPermission'))->getDeclaringClass()->getName();

        $this->assertSame($declaringClass, $controllerClass, "$controllerClass must declare its own permission mapping.");
    }

    /** The dashboard renders trees and nothing else, so `view` covers all of it. */
    public function testDashboardActionsRequireView(): void
    {
        $this->assertSame('menuBuilder:view', DashboardController::requiredPermissionForAction('index'));
    }

    // ---------------------------------------------------------------------
    // Which permission each group / item action requires
    // ---------------------------------------------------------------------

    public function testGroupsIndexAndEditRequireView(): void
    {
        $this->assertSame('menuBuilder:view', GroupsController::requiredPermissionForAction('index'));
        $this->assertSame('menuBuilder:view', GroupsController::requiredPermissionForAction('edit'));
    }

    public function testGroupsDeleteRequiresDelete(): void
    {
        $this->assertSame('menuBuilder:delete', GroupsController::requiredPermissionForAction('delete'));
    }

    public function testGroupsSaveAndDuplicateRequireManageSettings(): void
    {
        // Groups are structural/settings-level entities, not content — see
        // MenuBuilderGroup's docblock — so mutating one (whether creating,
        // editing, or duplicating) is gated by manageSettings rather than
        // the item-level create/edit permissions.
        $this->assertSame('menuBuilder:manageSettings', GroupsController::requiredPermissionForAction('save'));
        $this->assertSame('menuBuilder:manageSettings', GroupsController::requiredPermissionForAction('duplicate'));
    }

    public function testItemsEditRequiresView(): void
    {
        $this->assertSame('menuBuilder:view', ItemsController::requiredPermissionForAction('edit', false));
    }

    public function testItemsDeleteRequiresDelete(): void
    {
        $this->assertSame('menuBuilder:delete', ItemsController::requiredPermissionForAction('delete', false));
    }

    public function testItemsSaveRequiresCreateOnlyWhenNew(): void
    {
        $this->assertSame('menuBuilder:create', ItemsController::requiredPermissionForAction('save', true));
        $this->assertSame('menuBuilder:edit', ItemsController::requiredPermissionForAction('save', false));
    }

    public function testItemsDuplicateAlwaysRequiresCreate(): void
    {
        // Duplicating an existing item still produces a brand new one.
        $this->assertSame('menuBuilder:create', ItemsController::requiredPermissionForAction('duplicate', false));
    }

    public function testItemsToggleAndReorderRequireEdit(): void
    {
        $this->assertSame('menuBuilder:edit', ItemsController::requiredPermissionForAction('toggle', false));
        $this->assertSame('menuBuilder:edit', ItemsController::requiredPermissionForAction('reorder', false));
    }

    public function testGroupChangeAllowedOnlyForNewItemsOrUnchangedGroup(): void
    {
        $this->assertTrue(MenuBuilderItemService::isGroupChangeAllowed(null, 5));
        $this->assertTrue(MenuBuilderItemService::isGroupChangeAllowed(5, 5));
        $this->assertFalse(MenuBuilderItemService::isGroupChangeAllowed(5, 6));
    }

    // ---------------------------------------------------------------------
    // Bulk item actions take the permission of the operation they run
    // ---------------------------------------------------------------------

    public function testBulkDeleteRequiresDeletePermission(): void
    {
        $this->assertSame('menuBuilder:delete', ItemsController::requiredPermissionForAction('bulk', false, 'delete'));
    }

    public function testBulkEnableAndDisableRequireEditPermission(): void
    {
        $this->assertSame('menuBuilder:edit', ItemsController::requiredPermissionForAction('bulk', false, 'enable'));
        $this->assertSame('menuBuilder:edit', ItemsController::requiredPermissionForAction('bulk', false, 'disable'));
    }
}

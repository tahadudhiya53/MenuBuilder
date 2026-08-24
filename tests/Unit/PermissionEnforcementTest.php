<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\controllers\GroupsController;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;
use Tahadudhiya\MenuBuilder\services\MenuBuilderItemService;

/**
 * Covers the pure permission-decision logic factored out of
 * GroupsController/ItemsController::beforeAction() specifically so it can be
 * exercised without a booted Craft app/request (see those classes'
 * requiredPermissionForAction()). This is Phase 5's actual server-side
 * enforcement surface — the CP hides buttons a user can't use, but these
 * mappings are what a direct POST is checked against.
 */
class PermissionEnforcementTest extends TestCase
{
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
}

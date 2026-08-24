<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;

/**
 * Same static-method permission-mapping pattern as PermissionEnforcementTest,
 * extended to bulk item actions.
 */
class PhaseSixToTenPermissionTest extends TestCase
{
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

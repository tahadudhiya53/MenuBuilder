<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use craft\web\Controller;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\controllers\BaseMenuBuilderController;
use Tahadudhiya\MenuBuilder\controllers\DashboardController;
use Tahadudhiya\MenuBuilder\controllers\GroupsController;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;
use Tahadudhiya\MenuBuilder\controllers\PreviewController;
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
    /**
     * The only two actions in the plugin that render rather than write, and
     * so the only two that may legitimately answer a GET.
     */
    private const READ_ONLY_ACTIONS = ['index', 'edit'];

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
            'preview' => [PreviewController::class],
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

    /**
     * Preview renders a simulated tree and writes nothing at all (see
     * MenuBuilderPreviewTest), so it asks for no more than the dashboard
     * the editor reached it from.
     */
    public function testPreviewActionsRequireView(): void
    {
        $this->assertSame('menuBuilder:view', PreviewController::requiredPermissionForAction('index'));
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

    // ---------------------------------------------------------------------
    // Nothing escapes the mapping
    // ---------------------------------------------------------------------

    /**
     * Every `action*()` method a controller exposes must be answered by that
     * controller's mapping with one of the plugin's five permissions.
     *
     * The gate can only be as complete as the mapping is: a new action added
     * without a thought about permissions falls through to a `default` arm,
     * and this is what makes that a decision someone has to have made rather
     * than one that happened.
     *
     * @dataProvider controllerProvider
     * @param class-string $controllerClass
     */
    public function testEveryActionMethodIsAnsweredByTheMapping(string $controllerClass): void
    {
        $actions = self::actionIdsOf($controllerClass);

        $this->assertNotEmpty($actions, "$controllerClass exposes no actions at all.");

        foreach ($actions as $actionId) {
            $this->assertContains(
                self::requiredPermission($controllerClass, $actionId),
                BaseMenuBuilderController::CP_PERMISSIONS,
                "$controllerClass::action" . ucfirst($actionId) . '() maps to something that is not a MenuBuilder permission.'
            );
        }
    }

    /**
     * The `default` arm of a mutating controller's mapping must not be
     * `view`. Both mutating controllers route unrecognised action ids
     * through a default, so that default is the permission any future action
     * silently inherits — it has to be a writing-level one, or adding an
     * action would quietly open it to every reader.
     *
     * @dataProvider mutatingControllerProvider
     * @param class-string $controllerClass
     */
    public function testAnUnrecognisedActionDoesNotFallThroughToView(string $controllerClass): void
    {
        $this->assertNotSame(
            'menuBuilder:view',
            self::requiredPermission($controllerClass, 'someActionAddedLater'),
            "$controllerClass lets an unmapped action through on `view`."
        );
    }

    /**
     * @return array<string,array{class-string}>
     */
    public static function mutatingControllerProvider(): array
    {
        return [
            'groups' => [GroupsController::class],
            'items' => [ItemsController::class],
        ];
    }

    // ---------------------------------------------------------------------
    // Guards the gate depends on, and that no controller may weaken
    // ---------------------------------------------------------------------

    /**
     * CSRF protection is Craft's, and stays Craft's: it is on by default and
     * no controller here turns it off. ControllerAuthorizationTest proves a
     * tokenless POST is actually refused; this is the cheap check that keeps
     * a `false` from appearing anywhere in the first place.
     *
     * @dataProvider controllerProvider
     * @param class-string $controllerClass
     */
    public function testNoControllerDisablesCsrfValidation(string $controllerClass): void
    {
        $default = (new \ReflectionClass($controllerClass))->getDefaultProperties()['enableCsrfValidation'] ?? null;

        $this->assertTrue($default, "$controllerClass must leave CSRF validation enabled.");
        $this->assertStringNotContainsString(
            'enableCsrfValidation',
            self::classSource($controllerClass),
            "$controllerClass must not touch enableCsrfValidation."
        );
    }

    /**
     * No action of any controller may be reached anonymously. Craft reads
     * `$allowAnonymous` before this plugin's gate runs, so a non-false value
     * here would skip Craft's own login and `accessCp` checks entirely.
     *
     * @dataProvider controllerProvider
     * @param class-string $controllerClass
     */
    public function testNoActionIsAnonymouslyAccessible(string $controllerClass): void
    {
        $default = (new \ReflectionClass($controllerClass))->getDefaultProperties()['allowAnonymous'] ?? null;

        // An array would be a per-action allowlist, which is the shape that
        // could open one action while leaving the rest closed.
        $this->assertIsNotArray($default, "$controllerClass must not allow anonymous access per action.");
        $this->assertSame(
            Controller::ALLOW_ANONYMOUS_NEVER,
            (int)$default,
            "$controllerClass must not allow anonymous access to any action."
        );
    }

    /**
     * Every action that writes must refuse a GET. `requirePostRequest()` is
     * what makes a mutation unreachable by a link, an image tag or a
     * prefetch — none of which carry a CSRF token, but all of which arrive
     * with the victim's session.
     *
     * Read-only actions are exempt by name: `index` and `edit` render
     * screens, and are the only two actions in the plugin that legitimately
     * answer a GET.
     *
     * @dataProvider controllerProvider
     * @param class-string $controllerClass
     */
    public function testEveryWritingActionRequiresPost(string $controllerClass): void
    {
        $writing = array_values(array_filter(
            self::actionIdsOf($controllerClass),
            static fn(string $actionId): bool => !in_array($actionId, self::READ_ONLY_ACTIONS, true)
        ));

        if ($writing === []) {
            // A read-only controller. Asserted rather than skipped, so a
            // mutation added to the dashboard or the preview screen shows up
            // as a failure here instead of as an empty test.
            $this->assertSame(
                [],
                array_diff(self::actionIdsOf($controllerClass), self::READ_ONLY_ACTIONS),
                "$controllerClass gained an action that writes."
            );

            return;
        }

        foreach ($writing as $actionId) {
            $this->assertStringContainsString(
                'requirePostRequest()',
                self::methodSource($controllerClass, 'action' . ucfirst($actionId)),
                "$controllerClass::action" . ucfirst($actionId) . '() must require a POST.'
            );
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * The action ids a controller exposes, derived from its methods rather
     * than listed, so an action added later is included without anyone
     * having to remember to add it here.
     *
     * @param class-string $controllerClass
     * @return string[]
     */
    private static function actionIdsOf(string $controllerClass): array
    {
        $ids = [];

        foreach ((new \ReflectionClass($controllerClass))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // `action` followed by a capital: Yii's own inherited actions()
            // is not an action.
            if (preg_match('/^action[A-Z]/', $method->getName())) {
                $ids[] = lcfirst(substr($method->getName(), 6));
            }
        }

        return $ids;
    }

    /**
     * Each controller's mapping takes the arguments its own decision needs;
     * this calls whichever signature the class declares.
     *
     * @param class-string $controllerClass
     */
    private static function requiredPermission(string $controllerClass, string $actionId): string
    {
        $method = new \ReflectionMethod($controllerClass, 'requiredPermissionForAction');

        return $method->getNumberOfParameters() > 1
            ? $controllerClass::requiredPermissionForAction($actionId, false)
            : $controllerClass::requiredPermissionForAction($actionId);
    }

    /** @param class-string $class */
    private static function classSource(string $class): string
    {
        return (string)file_get_contents((new \ReflectionClass($class))->getFileName());
    }

    /** @param class-string $class */
    private static function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = (array)file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}

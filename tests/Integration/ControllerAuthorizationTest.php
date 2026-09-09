<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\elements\User;
use craft\models\UserGroup;
use craft\web\Request;
use craft\web\Response;
use Tahadudhiya\MenuBuilder\controllers\BaseMenuBuilderController;
use Tahadudhiya\MenuBuilder\controllers\DashboardController;
use Tahadudhiya\MenuBuilder\controllers\GroupsController;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;
use Tahadudhiya\MenuBuilder\controllers\PreviewController;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use yii\base\Action;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;

/**
 * The permission gate, executed for real.
*/
class ControllerAuthorizationTest extends CraftIntegrationTestCase
{
    /**
     * Users, by the permission they hold.
    */
    private const VIEW = 'view only';
    private const CREATE = 'create only';
    private const EDIT = 'edit only';
    private const DELETE = 'delete only';
    private const SETTINGS = 'manage settings only';
    private const NONE = 'no permissions';
    private const ADMIN = 'admin';

    /**
     * Holds `menuBuilder:edit` but not Craft's own `accessCp`.
    */
    private const NO_CP_ACCESS = 'no CP access';

    /** @var array<string,int> User IDs by label. */
    private static array $userIds = [];

    private static bool $usersLoaded = false;

    /**
     * A real saved item, so an action that reaches the database has something to act on.
    */
    private static int $itemId;

    private static int $groupId;

    /**
     * The console components this test swaps out.
     *
     * @var array<string,mixed>
    */
    private static array $originalComponents = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$usersLoaded) {
            return;
        }

        // User groups are a Pro feature, and the harness installs the default edition.
        Craft::$app->setEdition(Craft::Pro);

        foreach ([
            self::VIEW => ['menuBuilder:view'],
            self::CREATE => ['menuBuilder:create'],
            self::EDIT => ['menuBuilder:edit'],
            self::DELETE => ['menuBuilder:delete'],
            self::SETTINGS => ['menuBuilder:manageSettings'],
            self::NONE => [],
        ] as $label => $permissions) {
            self::$userIds[$label] = self::createUser($label, $permissions);
        }

        self::$userIds[self::ADMIN] = self::createUser(self::ADMIN, [], admin: true);
        self::$userIds[self::NO_CP_ACCESS] = self::createUser(
            self::NO_CP_ACCESS,
            ['menuBuilder:edit'],
            grantCpAccess: false,
        );

        $group = self::createMenu('authz', 'Authorization Fixture');
        self::$groupId = (int)$group->id;
        self::$itemId = (int)self::addItem($group, 'Fixture item', '/fixture')->id;

        self::$originalComponents = [
            'request' => Craft::$app->get('request'),
            'response' => Craft::$app->get('response'),
        ];

        self::$usersLoaded = true;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$originalComponents as $id => $component) {
            Craft::$app->set($id, $component);
        }

        Craft::$app->getUser()->setIdentity(null);

        parent::tearDownAfterClass();
    }

    /**
     * A real user in a real group holding real permission rows.
     *
     * @param string[] $permissions
    */
    private static function createUser(
        string $label,
        array $permissions,
        bool $admin = false,
        bool $grantCpAccess = true,
    ): int {
        $handle = 'authz' . ucfirst(str_replace(' ', '', ucwords($label)));

        $user = new User([
            'username' => $handle,
            'email' => $handle . '@example.test',
            'admin' => $admin,
        ]);

        if (!Craft::$app->getElements()->saveElement($user)) {
            throw new \RuntimeException("Could not create the \"$label\" user: " . json_encode($user->getErrors()));
        }

        if (!$admin) {
            $group = new UserGroup(['name' => "Authz: $label", 'handle' => $handle . 'Group']);

            if (!Craft::$app->getUserGroups()->saveGroup($group)) {
                throw new \RuntimeException("Could not create the \"$label\" group: " . json_encode($group->getErrors()));
            }

            $granted = $grantCpAccess ? array_merge(['accessCp'], $permissions) : $permissions;

            if (!Craft::$app->getUserPermissions()->saveGroupPermissions((int)$group->id, $granted)) {
                throw new \RuntimeException("Could not grant permissions to the \"$label\" group.");
            }

            Craft::$app->getUsers()->assignUserToGroups((int)$user->id, [(int)$group->id]);
        }

        return (int)$user->id;
    }

    private static function user(string $label): User
    {
        /** @var User $user */
        $user = User::find()->id(self::$userIds[$label])->status(null)->one();

        return $user;
    }

    // The matrix

    /**
     * Every action of every controller, and the *one* permission that opens it.
     *
     * @return array<string,array{class-string<BaseMenuBuilderController>,string,string,string,array<string,mixed>}>
    */
    public static function actionProvider(): array
    {
        return [
            // label => [controller, controllerId, actionId, permission that opens it, body]
            'dashboard: view a menu tree' => [DashboardController::class, 'dashboard', 'index', 'menuBuilder:view', []],
            'preview: render a menu' => [PreviewController::class, 'preview', 'index', 'menuBuilder:view', []],

            'groups: list menus' => [GroupsController::class, 'groups', 'index', 'menuBuilder:view', []],
            'groups: open menu settings' => [GroupsController::class, 'groups', 'edit', 'menuBuilder:view', []],
            'groups: save menu settings' => [GroupsController::class, 'groups', 'save', 'menuBuilder:manageSettings', []],
            'groups: duplicate a menu' => [GroupsController::class, 'groups', 'duplicate', 'menuBuilder:manageSettings', []],
            'groups: enable/disable a menu' => [GroupsController::class, 'groups', 'toggle', 'menuBuilder:manageSettings', []],
            'groups: delete a menu' => [GroupsController::class, 'groups', 'delete', 'menuBuilder:delete', []],

            'items: open the item editor' => [ItemsController::class, 'items', 'edit', 'menuBuilder:view', []],
            'items: save a new item' => [ItemsController::class, 'items', 'save', 'menuBuilder:create', ['itemId' => 0]],
            'items: save a new item (no itemId posted)' => [ItemsController::class, 'items', 'save', 'menuBuilder:create', []],
            'items: save an existing item' => [ItemsController::class, 'items', 'save', 'menuBuilder:edit', ['itemId' => 1]],
            'items: duplicate an item' => [ItemsController::class, 'items', 'duplicate', 'menuBuilder:create', []],
            'items: enable/disable an item' => [ItemsController::class, 'items', 'toggle', 'menuBuilder:edit', []],
            'items: reorder the tree' => [ItemsController::class, 'items', 'reorder', 'menuBuilder:edit', []],
            'items: delete an item' => [ItemsController::class, 'items', 'delete', 'menuBuilder:delete', []],
            'items: bulk enable' => [ItemsController::class, 'items', 'bulk', 'menuBuilder:edit', ['op' => 'enable']],
            'items: bulk disable' => [ItemsController::class, 'items', 'bulk', 'menuBuilder:edit', ['op' => 'disable']],
            'items: bulk delete' => [ItemsController::class, 'items', 'bulk', 'menuBuilder:delete', ['op' => 'delete']],
            'items: bulk with no op posted' => [ItemsController::class, 'items', 'bulk', 'menuBuilder:edit', []],
        ];
    }

    /**
     * The whole point of the phase: for each action, exactly one of the five single-permission
     * users gets through, and the other four are refused — by the server, on a request that never
     * went near the UI.
     *
     * @dataProvider actionProvider
     * @param class-string<BaseMenuBuilderController> $controllerClass
     * @param array<string,mixed> $body
    */
    public function testOnlyTheOnePermissionThatOpensAnActionOpensIt(
        string $controllerClass,
        string $controllerId,
        string $actionId,
        string $permission,
        array $body,
    ): void {
        $holders = [
            'menuBuilder:view' => self::VIEW,
            'menuBuilder:create' => self::CREATE,
            'menuBuilder:edit' => self::EDIT,
            'menuBuilder:delete' => self::DELETE,
            'menuBuilder:manageSettings' => self::SETTINGS,
        ];

        foreach ($holders as $held => $label) {
            $shouldPass = $held === $permission;
            $passed = $this->gateAllows($controllerClass, $controllerId, $actionId, $label, $body);

            $this->assertSame(
                $shouldPass,
                $passed,
                sprintf(
                    'A user holding only %s %s have been let through to %s/%s, but the gate %s them.',
                    $held,
                    $shouldPass ? 'should' : 'should not',
                    $controllerId,
                    $actionId,
                    $passed ? 'admitted' : 'refused',
                ),
            );
        }
    }

    /**
     * @dataProvider actionProvider
     * @param class-string<BaseMenuBuilderController> $controllerClass
     * @param array<string,mixed> $body
    */
    public function testAUserWithNoPermissionsIsRefusedEveryAction(
        string $controllerClass,
        string $controllerId,
        string $actionId,
        string $permission,
        array $body,
    ): void {
        $this->assertFalse(
            $this->gateAllows($controllerClass, $controllerId, $actionId, self::NONE, $body),
            "A user with no MenuBuilder permissions reached $controllerId/$actionId."
        );
    }

    /**
     * Admin bypass, proven by an admin who holds none of the five permissions: if
     * `$currentUser->admin` stopped short-circuiting the check, every row here would fail.
     *
     * @dataProvider actionProvider
     * @param class-string<BaseMenuBuilderController> $controllerClass
     * @param array<string,mixed> $body
    */
    public function testAnAdminHoldingNoPermissionsReachesEveryAction(
        string $controllerClass,
        string $controllerId,
        string $actionId,
        string $permission,
        array $body,
    ): void {
        $admin = self::user(self::ADMIN);

        $this->assertTrue((bool)$admin->admin);
        // `can()` answers true for an admin by definition, so the fixture's emptiness has to be
        // checked against what is actually stored.
        $this->assertSame(
            [],
            Craft::$app->getUserPermissions()->getPermissionsByUserId((int)$admin->id),
            'The admin fixture is supposed to hold no permissions of its own.'
        );

        $this->assertTrue(
            $this->gateAllows($controllerClass, $controllerId, $actionId, self::ADMIN, $body),
            "An admin was refused $controllerId/$actionId."
        );
    }

    /**
     * Direct URL access with no session at all.
     *
     * @dataProvider actionProvider
     * @param class-string<BaseMenuBuilderController> $controllerClass
     * @param array<string,mixed> $body
    */
    public function testAnAnonymousRequestIsRefusedEveryAction(
        string $controllerClass,
        string $controllerId,
        string $actionId,
        string $permission,
        array $body,
    ): void {
        $this->assertFalse(
            $this->gateAllows($controllerClass, $controllerId, $actionId, null, $body),
            "A logged-out request reached $controllerId/$actionId."
        );
    }

    /**
     * MenuBuilder's own permissions are not a way into the control panel.
     *
     * @dataProvider actionProvider
     * @param class-string<BaseMenuBuilderController> $controllerClass
     * @param array<string,mixed> $body
    */
    public function testMenuBuilderPermissionsDoNotGrantControlPanelAccess(
        string $controllerClass,
        string $controllerId,
        string $actionId,
        string $permission,
        array $body,
    ): void {
        $user = self::user(self::NO_CP_ACCESS);

        $this->assertTrue($user->can('menuBuilder:edit'), 'The fixture is supposed to hold menuBuilder:edit.');
        $this->assertFalse($user->can('accessCp'), 'The fixture is supposed to lack accessCp.');

        $this->assertFalse(
            $this->gateAllows($controllerClass, $controllerId, $actionId, self::NO_CP_ACCESS, $body),
            "A user without accessCp reached $controllerId/$actionId."
        );
    }

    /**
     * An AJAX request is the same request.
     *
     * @dataProvider actionProvider
     * @param class-string<BaseMenuBuilderController> $controllerClass
     * @param array<string,mixed> $body
    */
    public function testAjaxRequestsAreGatedIdenticallyToPageRequests(
        string $controllerClass,
        string $controllerId,
        string $actionId,
        string $permission,
        array $body,
    ): void {
        $this->assertFalse(
            $this->gateAllows($controllerClass, $controllerId, $actionId, self::NONE, $body, ajax: true),
            "An AJAX request from a user with no permissions reached $controllerId/$actionId."
        );

        $this->assertTrue(
            $this->gateAllows($controllerClass, $controllerId, $actionId, self::ADMIN, $body, ajax: true),
            "An AJAX request from an admin was refused $controllerId/$actionId."
        );
    }

    /**
     * Every one of these actions is control-panel-only.
     *
     * @dataProvider actionProvider
     * @param class-string<BaseMenuBuilderController> $controllerClass
     * @param array<string,mixed> $body
    */
    public function testASiteRequestCannotReachAnyAction(
        string $controllerClass,
        string $controllerId,
        string $actionId,
        string $permission,
        array $body,
    ): void {
        $this->expectException(BadRequestHttpException::class);

        $this->runGate($controllerClass, $controllerId, $actionId, self::ADMIN, $body, cpRequest: false);
    }

    /**
     * The gate refuses with a 403, not with a redirect or a silent `false` — so a hand-written
     * POST gets an error rather than, say, a 302 that a script would follow into a second attempt.
    */
    public function testAnUnauthorizedUserIsRefusedWithForbidden(): void
    {
        $this->expectException(ForbiddenHttpException::class);

        $this->runGate(ItemsController::class, 'items', 'delete', self::EDIT);
    }

    // CSRF

    /**
     * CSRF validation is Craft's, but it is only Craft's for as long as no controller in this
     * plugin switches it off — and holding the right permission must not substitute for holding a
     * token.
     *
     * @dataProvider mutatingActionProvider
     * @param class-string<BaseMenuBuilderController> $controllerClass
    */
    public function testCsrfIsStillRequiredForEveryMutation(
        string $controllerClass,
        string $controllerId,
        string $actionId,
    ): void {
        $this->expectException(BadRequestHttpException::class);

        $this->runGate($controllerClass, $controllerId, $actionId, self::ADMIN, validateCsrf: true);
    }

    /**
     * Every action that writes.
     *
     * @return array<string,array{class-string<BaseMenuBuilderController>,string,string}>
    */
    public static function mutatingActionProvider(): array
    {
        return [
            'groups: save' => [GroupsController::class, 'groups', 'save'],
            'groups: duplicate' => [GroupsController::class, 'groups', 'duplicate'],
            'groups: toggle' => [GroupsController::class, 'groups', 'toggle'],
            'groups: delete' => [GroupsController::class, 'groups', 'delete'],
            'items: save' => [ItemsController::class, 'items', 'save'],
            'items: duplicate' => [ItemsController::class, 'items', 'duplicate'],
            'items: toggle' => [ItemsController::class, 'items', 'toggle'],
            'items: reorder' => [ItemsController::class, 'items', 'reorder'],
            'items: delete' => [ItemsController::class, 'items', 'delete'],
            'items: bulk' => [ItemsController::class, 'items', 'bulk'],
        ];
    }

    /**
     * A GET must not mutate anything, whoever sends it.
     *
     * @dataProvider mutatingActionProvider
     * @param class-string<BaseMenuBuilderController> $controllerClass
    */
    public function testEveryMutationRefusesAGetRequest(
        string $controllerClass,
        string $controllerId,
        string $actionId,
    ): void {
        $controller = $this->controller($controllerClass, $controllerId, self::ADMIN, [], method: 'GET');

        $this->expectException(MethodNotAllowedHttpException::class);

        $controller->{'action' . ucfirst($actionId)}();
    }

    // Smuggling the operation past the gate

    /**
     * `bulk` is the one action whose permission depends on a posted value, so it is the one place a
     * request could be shaped to be checked as one operation and executed as another.
    */
    public function testABulkOpInTheQueryStringCannotBeSmuggledPastTheEditGate(): void
    {
        $item = self::addItem(
            MenuBuilder::getInstance()->groups->getById(self::$groupId),
            'Smuggle target',
            '/smuggle'
        );

        $controller = $this->controller(
            ItemsController::class,
            'items',
            self::EDIT,
            ['ids' => [$item->id]],
            queryParams: ['op' => 'delete'],
            // The JSON branch, because the CP's bulk toolbar posts JSON and the alternative branch
            // flashes to a session a console-booted app doesn't have.
            ajax: true,
        );

        $this->assertInstanceOf(ItemsController::class, $controller);

        // The gate admitted this as an edit-level request…
        $this->assertTrue($controller->beforeAction(new Action('bulk', $controller)));

        $controller->actionBulk();

        // …and the action agreed with it: nothing was deleted.
        $this->assertNotNull(
            MenuBuilder::getInstance()->items->getById((int)$item->id),
            'A bulk delete was smuggled past the edit gate through the query string.'
        );
    }

    /**
     * The other request-shaped decision: `save` needs `create` when it is making a new item and
     * `edit` when it is changing one.
    */
    public function testSaveCannotBeReshapedToDodgeCreateOrEdit(): void
    {
        $this->assertFalse(
            $this->gateAllows(ItemsController::class, 'items', 'save', self::EDIT, []),
            'An editor without `create` created a new item by omitting itemId.'
        );

        $this->assertFalse(
            $this->gateAllows(ItemsController::class, 'items', 'save', self::CREATE, ['itemId' => self::$itemId]),
            'A creator without `edit` edited an existing item by posting its itemId.'
        );
    }

    /**
     * A refusal must happen before anything is written.
    */
    public function testARefusedMutationChangesNothing(): void
    {
        $before = MenuBuilder::getInstance()->items->getById(self::$itemId);
        $this->assertInstanceOf(MenuBuilderItem::class, $before);

        $this->assertFalse(
            $this->gateAllows(ItemsController::class, 'items', 'toggle', self::VIEW, ['id' => self::$itemId]),
            'A view-only user reached the toggle action.'
        );

        $after = MenuBuilder::getInstance()->items->getById(self::$itemId);

        $this->assertSame($before->enabled, $after->enabled);
    }

    // Harness

    /** @param array<string,mixed> $body */
    private function gateAllows(
        string $controllerClass,
        string $controllerId,
        string $actionId,
        ?string $userLabel,
        array $body = [],
        bool $ajax = false,
    ): bool {
        try {
            return $this->runGate($controllerClass, $controllerId, $actionId, $userLabel, $body, ajax: $ajax);
        } catch (\Throwable) {
            // Any refusal is a refusal.
            return false;
        }
    }

    /** @param array<string,mixed> $body */
    private function runGate(
        string $controllerClass,
        string $controllerId,
        string $actionId,
        ?string $userLabel,
        array $body = [],
        bool $ajax = false,
        bool $cpRequest = true,
        bool $validateCsrf = false,
    ): bool {
        $controller = $this->controller(
            $controllerClass,
            $controllerId,
            $userLabel,
            $body,
            ajax: $ajax,
            cpRequest: $cpRequest,
            validateCsrf: $validateCsrf,
        );

        return $controller->beforeAction(new Action($actionId, $controller));
    }

    /**
     * A controller wired to a real CP request, with a real user logged in.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $queryParams
    */
    private function controller(
        string $controllerClass,
        string $controllerId,
        ?string $userLabel,
        array $body = [],
        array $queryParams = [],
        string $method = 'POST',
        bool $ajax = false,
        bool $cpRequest = true,
        bool $validateCsrf = false,
    ): BaseMenuBuilderController {
        $request = self::buildRequest($method, $cpRequest, $ajax, $body, $queryParams);

        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
        Craft::$app->getUser()->setIdentity($userLabel !== null ? self::user($userLabel) : null);

        /** @var BaseMenuBuilderController $controller */
        $controller = new $controllerClass($controllerId, MenuBuilder::getInstance());
        $controller->enableCsrfValidation = $validateCsrf;

        return $controller;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $queryParams
    */
    private static function buildRequest(
        string $method,
        bool $cpRequest,
        bool $ajax,
        array $body,
        array $queryParams,
    ): Request {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['SCRIPT_FILENAME'] = CRAFT_BASE_PATH . '/web/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        // `admin` is the default cpTrigger, and it is what makes getIsCpRequest() true — the site
        // path below is the same request arriving at the front end instead.
        $_SERVER['REQUEST_URI'] = $cpRequest ? '/admin/actions/menu-builder' : '/menu-builder';
        $_SERVER['SERVER_NAME'] = 'primary.test';
        $_SERVER['HTTP_HOST'] = 'primary.test';
        $_SERVER['HTTPS'] = 'on';

        if ($ajax) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
        } else {
            unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']);
        }

        $request = new Request(['cookieValidationKey' => 'menu-builder-integration-tests']);
        $request->setBodyParams($body);
        $request->setQueryParams($queryParams);

        return $request;
    }
}

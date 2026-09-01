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
 *
 * ControllerPermissionTest and CpAffordanceTest pin the *decisions* — pure
 * static mappings, checked without a booted app. Neither of them runs
 * {@see BaseMenuBuilderController::beforeAction()}, which is the thing that
 * actually stands between a hand-written POST and the database. So neither
 * would have noticed a permission that Craft never registered (making
 * `can()` answer false for everyone), a gate that consulted the request
 * instead of the session, or a CSRF check switched off somewhere in the
 * inheritance chain.
 *
 * This test runs the gate itself: real users, in real user groups, holding
 * real permission rows, against a real CP `craft\web\Request`, for every
 * action of every controller. Nothing here mirrors the plugin's own logic —
 * the expectation column is written out by hand, one row per action, so a
 * change to a mapping has to be restated here to pass.
 *
 * The UI is not consulted at any point, which is the point: hiding a button
 * is not a security boundary, and every case below is the request that
 * arrives when someone ignores the hidden button and posts anyway.
 */
class ControllerAuthorizationTest extends CraftIntegrationTestCase
{
    /** Users, by the permission they hold. Keys are the labels used in failure messages. */
    private const VIEW = 'view only';
    private const CREATE = 'create only';
    private const EDIT = 'edit only';
    private const DELETE = 'delete only';
    private const SETTINGS = 'manage settings only';
    private const NONE = 'no permissions';
    private const ADMIN = 'admin';

    /**
     * Holds `menuBuilder:edit` but not Craft's own `accessCp`. Craft refuses
     * the request before this plugin is consulted; the case is here so that
     * fact is pinned rather than assumed.
     */
    private const NO_CP_ACCESS = 'no CP access';

    /** @var array<string,int> User IDs by label. */
    private static array $userIds = [];

    private static bool $usersLoaded = false;

    /** A real saved item, so an action that reaches the database has something to act on. */
    private static int $itemId;

    private static int $groupId;

    /**
     * The console components this test swaps out. Put back afterwards so the
     * rest of the suite isn't left running against a half-web application.
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

        // User groups are a Pro feature, and the harness installs the
        // default edition. Nothing else in the suite reads the edition.
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
     * The admin holds *no* permissions at all, on purpose: "admin bypasses
     * the gate" is only proven by an admin who would fail every check if the
     * bypass were removed.
     *
     * Every non-admin also gets Craft's own `accessCp`, because a control
     * panel user who lacks it never reaches a plugin controller at all
     * (craft\web\Controller enforces it first) — without it the matrix below
     * would pass for the wrong reason, refusing everyone regardless of what
     * this plugin's gate decided.
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

    // ---------------------------------------------------------------------
    // The matrix
    // ---------------------------------------------------------------------

    /**
     * Every action of every controller, and the *one* permission that opens
     * it. Written out by hand rather than read from
     * `requiredPermissionForAction()`: a test that asked the code what it
     * requires would agree with any answer it gave.
     *
     * The `body` column is what the gate reads to decide — `itemId` (is this
     * save creating something?) and `op` (which bulk operation?) — and is
     * posted for real, so a gate that read them from somewhere else than the
     * action does would show up here.
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
     * The whole point of the phase: for each action, exactly one of the five
     * single-permission users gets through, and the other four are refused —
     * by the server, on a request that never went near the UI.
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
     * Admin bypass, proven by an admin who holds none of the five
     * permissions: if `$currentUser->admin` stopped short-circuiting the
     * check, every row here would fail.
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
        // `can()` answers true for an admin by definition, so the fixture's
        // emptiness has to be checked against what is actually stored.
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
     * Direct URL access with no session at all. A logged-out request must
     * never reach an action, whatever it posts.
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
     * Craft requires `accessCp` for every CP request, and a user granted
     * `menuBuilder:edit` alone must still be refused — the plugin's gate is
     * an additional check, never a replacement for Craft's.
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
     * An AJAX request is the same request. The CP's tree, bulk toolbar and
     * slide-out editor all post JSON, and a gate that answered a JSON
     * request differently — or answered `{success: true}` with a 200 — would
     * be a hole every one of those endpoints shares.
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
     * Every one of these actions is control-panel-only. A front-end request
     * that happened to guess the route must not reach one even when the
     * user behind it is an admin — `requireCpRequest()` is what makes the CP
     * the only surface, and it runs before any of the mutations.
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
     * The gate refuses with a 403, not with a redirect or a silent `false` —
     * so a hand-written POST gets an error rather than, say, a 302 that a
     * script would follow into a second attempt.
     */
    public function testAnUnauthorizedUserIsRefusedWithForbidden(): void
    {
        $this->expectException(ForbiddenHttpException::class);

        $this->runGate(ItemsController::class, 'items', 'delete', self::EDIT);
    }

    // ---------------------------------------------------------------------
    // CSRF
    // ---------------------------------------------------------------------

    /**
     * CSRF validation is Craft's, but it is only Craft's for as long as no
     * controller in this plugin switches it off — and holding the right
     * permission must not substitute for holding a token. Run as the admin,
     * so the only thing left that can refuse the request is the token check.
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
     * Every action that writes. Kept separate from actionProvider() because
     * these are the ones that must also refuse a GET and a missing CSRF
     * token — the read-only screens legitimately answer both.
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
     * A GET must not mutate anything, whoever sends it. Run as the admin and
     * against the real action method, past the gate, so what is being tested
     * is the action's own `requirePostRequest()` rather than the permission
     * check that happens to come first.
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

    // ---------------------------------------------------------------------
    // Smuggling the operation past the gate
    // ---------------------------------------------------------------------

    /**
     * `bulk` is the one action whose permission depends on a posted value,
     * so it is the one place a request could be shaped to be checked as one
     * operation and executed as another. The gate reads `op` from the body;
     * this proves the action does too — an `op` supplied only in the query
     * string is checked as (and stays) a non-delete.
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
            // The JSON branch, because the CP's bulk toolbar posts JSON and
            // the alternative branch flashes to a session a console-booted
            // app doesn't have.
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
     * The other request-shaped decision: `save` needs `create` when it is
     * making a new item and `edit` when it is changing one. An editor
     * without `create` must not be able to create by posting no `itemId`,
     * and a creator without `edit` must not be able to edit by posting one.
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
     * A refusal must happen before anything is written. Proven end-to-end
     * rather than by inspection: the item is still enabled afterwards.
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

    // ---------------------------------------------------------------------
    // Harness
    // ---------------------------------------------------------------------

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
            // Any refusal is a refusal. Which exception it is, is pinned
            // separately (see testAnUnauthorizedUserIsRefusedWithForbidden()
            // and testASiteRequestCannotReachAnyAction()) so a boolean
            // matrix doesn't have to carry an exception column.
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
     * CSRF validation is off by default: with it on, every row of the
     * permission matrix would fail for the same uninteresting reason (a test
     * harness cannot set a browser cookie), and the matrix would prove
     * nothing about permissions. It is switched back on by the one test
     * whose subject it is.
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
        // `admin` is the default cpTrigger, and it is what makes
        // getIsCpRequest() true — the site path below is the same request
        // arriving at the front end instead.
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

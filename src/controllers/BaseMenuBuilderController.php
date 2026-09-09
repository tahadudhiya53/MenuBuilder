<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\web\Controller;
use yii\base\Action;
use yii\web\ForbiddenHttpException;

/**
 * The one place the CP permission gate is expressed: require a CP request, then allow admins or
 * holders of one specific permission.
*/
abstract class BaseMenuBuilderController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        $currentUser = Craft::$app->getUser()->getIdentity();
        $requiredPermission = $this->requiredPermission($action);

        if (!$currentUser || (!$currentUser->admin && !$currentUser->can($requiredPermission))) {
            throw new ForbiddenHttpException($this->permissionDeniedMessage());
        }

        return true;
    }

    /**
     * The permission the given action requires.
    */
    abstract protected function requiredPermission(Action $action): string;

    /**
     * Every permission this plugin registers, in the order the CP thinks about them.
    */
    public const CP_PERMISSIONS = [
        'menuBuilder:view',
        'menuBuilder:create',
        'menuBuilder:edit',
        'menuBuilder:delete',
        'menuBuilder:manageSettings',
    ];

    /**
     * The five affordance flags the CP templates use to decide which controls to render at all,
     * derived from one user's admin status and the permissions they hold.
     *
     * @param string[] $grantedPermissions
     * @return array{canView: bool, canCreate: bool, canEdit: bool, canDelete: bool, canManageSettings: bool}
    */
    public static function cpAffordances(bool $isAdmin, array $grantedPermissions): array
    {
        $can = static fn(string $permission): bool => $isAdmin || in_array($permission, $grantedPermissions, true);

        return [
            'canView' => $can('menuBuilder:view'),
            'canCreate' => $can('menuBuilder:create'),
            'canEdit' => $can('menuBuilder:edit'),
            'canDelete' => $can('menuBuilder:delete'),
            'canManageSettings' => $can('menuBuilder:manageSettings'),
        ];
    }

    /**
     * {@see cpAffordances()} for the user making this request.
     *
     * @return array{canView: bool, canCreate: bool, canEdit: bool, canDelete: bool, canManageSettings: bool}
    */
    protected function currentUserAffordances(): array
    {
        $currentUser = Craft::$app->getUser()->getIdentity();

        if (!$currentUser) {
            return self::cpAffordances(false, []);
        }

        $granted = array_values(array_filter(
            self::CP_PERMISSIONS,
            static fn(string $permission): bool => $currentUser->can($permission)
        ));

        return self::cpAffordances((bool)$currentUser->admin, $granted);
    }

    protected function permissionDeniedMessage(): string
    {
        return 'You are not permitted to manage navigation menus.';
    }

    /** @return array<mixed,mixed> */
    protected function bodyArray(string $name): array
    {
        $value = Craft::$app->getRequest()->getBodyParam($name, []);

        return is_array($value) ? $value : [];
    }

    protected function bodyString(string $name, string $default = ''): string
    {
        $value = Craft::$app->getRequest()->getBodyParam($name, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}

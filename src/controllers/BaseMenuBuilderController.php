<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\web\Controller;
use yii\base\Action;
use yii\web\ForbiddenHttpException;

/**
 * The one place the CP permission gate is expressed: require a CP request,
 * then allow admins or holders of one specific permission. Subclasses only
 * declare *which* permission an action needs ({@see requiredPermission()}),
 * so the check itself has a single implementation and can't drift into a
 * silent authorization hole in one controller.
 *
 * Each subclass maps its actions in a pure static
 * `requiredPermissionForAction()` — see ControllerPermissionTest — which
 * this class only consumes.
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
     * The permission the given action requires. Implementations delegate to
     * their own static mapping, supplying whatever request-derived context
     * that mapping needs (e.g. new-vs-existing save, bulk op).
     */
    abstract protected function requiredPermission(Action $action): string;

    protected function permissionDeniedMessage(): string
    {
        return 'You are not permitted to manage navigation menus.';
    }

    /**
     * @return array<mixed,mixed>
     */
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

<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use yii\base\Action;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

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

    /**
     * The 403 body for this controller. A constant rather than an
     * overridden method so a read-only screen states only the wording it
     * differs by — see DashboardController and PreviewController, which used
     * to carry an identical three-line override each.
     */
    protected const PERMISSION_DENIED_MESSAGE = 'You are not permitted to manage navigation menus.';

    protected function permissionDeniedMessage(): string
    {
        return static::PERMISSION_DENIED_MESSAGE;
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

    /**
     * The menu named by a CP route's `groupHandle`, or the redirect to send
     * instead when there is no such menu.
     *
     *     $group = $this->groupByHandleOrRedirect($groupHandle);
     *
     *     if ($group instanceof Response) {
     *         return $group;
     *     }
     *
     * A missing menu is not a 404 on these screens: the handle comes from a
     * link or a bookmark that was valid until someone deleted or renamed the
     * menu, and the useful answer is the index with an explanation, not an
     * error page. Written once so the dashboard and the preview screen —
     * which had the same four lines each — cannot start disagreeing about
     * which of the two it is.
     */
    protected function groupByHandleOrRedirect(string $handle): MenuBuilderGroup|Response
    {
        $group = MenuBuilder::getInstance()->groups->getByHandle($handle);

        if ($group) {
            return $group;
        }

        Craft::$app->getSession()->setError(Craft::t('menu-builder', 'That navigation menu doesn’t exist.'));

        return $this->redirect(UrlHelper::cpUrl('menu-builder'));
    }

    /**
     * A posted ID-shaped value as a positive-or-any int, or null when the
     * field was absent or left blank — the shape almost every optional
     * numeric field on the two edit forms posts (`parentId`, `elementId`,
     * `image`, `maxDepth`, `newParentId`, a dynamic source's id and limit).
     *
     * Written out once so "blank means null, not zero" cannot be got right
     * in six places and wrong in the seventh: a `parentId` of `0` is not
     * "top level" to the item service, it is a parent that doesn't exist.
     * Non-scalars (a tampered `parentId[]=1`) are null for the same reason —
     * an array is not an ID, and casting one would silently produce `1`.
     */
    protected function bodyIntOrNull(string $name): ?int
    {
        $value = Craft::$app->getRequest()->getBodyParam($name);

        return is_scalar($value) && (string)$value !== '' ? (int)$value : null;
    }

    /**
     * The response a CP mutation gives on both of the paths it can be called
     * from: a JSON answer for the tree/index JS, and a flash-plus-redirect
     * for the same action posted from a plain form (no JS, or a form action
     * on the edit screen).
     *
     * One implementation because the two halves have to stay in step: the
     * JSON path deliberately carries **no** message (the caller raises its
     * own notification from the data, and a second one from `asSuccess()`
     * showed the editor the same thing twice), while the redirect path is
     * the only one that may set a flash — a flash set on the JSON path sits
     * unread in the session and then surfaces on the next full page load, as
     * a stale notice about something the editor was already told.
     *
     * A destination is taken as a *URL*, never as an already-built response:
     * `redirect()` doesn't make one, it stamps `Location` and a 302 onto the
     * shared `Craft::$app->response` and hands that same object back. Built
     * eagerly at the call site it would land on the JSON path too, where
     * `asJson()` sets only `format` and `data` — leaving the CP's own axios
     * a 302 it rejects as an error, so a delete that worked reports that it
     * didn't. Building it here means it is built only where it is used.
     *
     * @param array<string,mixed> $data Extra JSON payload; ignored on the redirect path.
     * @param string|null $successMessage Flash wording for the redirect path; a generic one is used when omitted.
     * @param string|null $redirectUrl Where the redirect path goes; the posted redirect URL when omitted.
     */
    protected function respondToMutation(
        bool $success,
        string $failureMessage,
        array $data = [],
        ?string $successMessage = null,
        ?string $redirectUrl = null,
    ): Response {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $success ? $this->asSuccess(data: $data) : $this->asFailure($failureMessage);
        }

        if ($success) {
            Craft::$app->getSession()->setSuccess(
                $successMessage ?? Craft::t('menu-builder', 'Changes saved.')
            );
        } else {
            Craft::$app->getSession()->setError($failureMessage);
        }

        return $redirectUrl !== null ? $this->redirect($redirectUrl) : $this->redirectToPostedUrl();
    }
}

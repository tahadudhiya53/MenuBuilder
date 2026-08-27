<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use yii\base\Action;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class GroupsController extends BaseMenuBuilderController
{
    protected function requiredPermission(Action $action): string
    {
        return self::requiredPermissionForAction($action->id);
    }

    protected function permissionDeniedMessage(): string
    {
        return 'You are not permitted to manage navigation groups.';
    }

    /**
     * Pure mapping from action to the permission it requires, factored out
     * so it's unit-testable without a booted Craft app. Groups are
     * structural/settings-level entities (name, handle, maxDepth, cssClass,
     * htmlAttributes — see MenuBuilderGroup) rather than content, so
     * creating/editing/duplicating one is gated by `manageSettings` — the
     * `create`/`edit` permissions govern items within a group instead (see
     * ItemsController::requiredPermissionForAction()).
     */
    public static function requiredPermissionForAction(string $actionId): string
    {
        return match ($actionId) {
            'index', 'edit' => 'menuBuilder:view',
            'delete' => 'menuBuilder:delete',
            default => 'menuBuilder:manageSettings',
        };
    }

    /**
     * Quick enable/disable without opening the full edit form; parity with
     * ItemsController::actionToggle().
     */
    public function actionToggle(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $group = MenuBuilder::getInstance()->groups->getById($id);

        if (!$group) {
            return $this->asFailure(Craft::t('menu-builder', 'Navigation group not found.'));
        }

        $group->enabled = !$group->enabled;
        $success = MenuBuilder::getInstance()->groups->save($group, runValidation: false);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $success
                ? $this->asSuccess(data: ['enabled' => $group->enabled])
                : $this->asFailure(Craft::t('menu-builder', 'Couldn’t update that navigation group.'));
        }

        if ($success) {
            Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Navigation group updated.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'Couldn’t update that navigation group.'));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionIndex(): Response
    {
        $groups = MenuBuilder::getInstance()->groups->getAll();
        $currentUser = Craft::$app->getUser()->getIdentity();

        $rows = array_map(fn(MenuBuilderGroup $group) => [
            'group' => $group,
            'itemCount' => MenuBuilder::getInstance()->groups->countItems($group->id),
        ], $groups);

        return $this->renderTemplate('menu-builder/groups/_index', [
            'rows' => $rows,
            'canManageSettings' => $currentUser && ($currentUser->admin || $currentUser->can('menuBuilder:manageSettings')),
            'canDelete' => $currentUser && ($currentUser->admin || $currentUser->can('menuBuilder:delete')),
        ]);
    }

    public function actionEdit(?int $groupId = null, ?MenuBuilderGroup $group = null): Response
    {
        if ($group === null) {
            if ($groupId !== null) {
                $group = MenuBuilder::getInstance()->groups->getById($groupId);

                if (!$group) {
                    throw new NotFoundHttpException('Navigation group not found.');
                }
            } else {
                $group = new MenuBuilderGroup();
            }
        }

        return $this->renderTemplate('menu-builder/groups/_edit', [
            'group' => $group,
            'isNew' => $group->id === null,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $groupId = (int)$request->getBodyParam('groupId', 0);
        $group = $groupId ? MenuBuilder::getInstance()->groups->getById($groupId) : new MenuBuilderGroup();

        if (!$group) {
            throw new NotFoundHttpException('Navigation group not found.');
        }

        $group->name = $this->bodyString('name');
        $group->handle = $this->bodyString('handle');
        $description = $request->getBodyParam('description');
        $group->description = is_scalar($description) ? (string)$description : null;
        $group->enabled = (bool)$request->getBodyParam('enabled', false);
        $group->cssClass = $this->bodyString('cssClass') ?: null;
        $maxDepth = $request->getBodyParam('maxDepth');
        $group->maxDepth = ($maxDepth !== null && $maxDepth !== '') ? (int)$maxDepth : null;
        $group->htmlAttributes = LinkAttributeHelper::parseAttributeLines($this->bodyString('htmlAttributes'));
        $group->siteIds = ConfigHelper::normalizeIdList($request->getBodyParam('siteIds'));

        if (!MenuBuilder::getInstance()->groups->save($group)) {
            // asModelFailure() sets the error flash itself — setting one
            // here as well surfaced the same message twice in the CP.
            return $this->asModelFailure($group, Craft::t('menu-builder', 'Couldn’t save navigation group.'), 'group');
        }

        Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Navigation group saved.'));

        return $this->redirectToPostedUrl($group, UrlHelper::cpUrl('menu-builder/' . $group->handle));
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $clone = MenuBuilder::getInstance()->groups->duplicate($id);

        if ($clone === null) {
            return $this->asFailure(Craft::t('menu-builder', 'Couldn’t duplicate that navigation group.'));
        }

        return $this->asSuccess(data: [
            'id' => $clone->id,
            'url' => UrlHelper::cpUrl('menu-builder/' . $clone->handle),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $success = MenuBuilder::getInstance()->groups->deleteById($id);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $success
                ? $this->asSuccess()
                : $this->asFailure(Craft::t('menu-builder', 'Couldn’t delete that navigation group.'));
        }

        if ($success) {
            Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Navigation group deleted.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'Couldn’t delete that navigation group.'));
        }

        return $this->redirect(UrlHelper::cpUrl('menu-builder'));
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class GroupsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $currentUser = Craft::$app->getUser()->getIdentity();
        $requiredPermission = self::requiredPermissionForAction($action->id);

        if (!$currentUser || (!$currentUser->admin && !$currentUser->can($requiredPermission))) {
            throw new ForbiddenHttpException('You are not permitted to manage navigation groups.');
        }

        return true;
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
     * Quick enable/disable, parity with ItemsController::actionToggle() —
     * previously a group could only be toggled by opening the full edit form.
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
        $group->htmlAttributes = $this->parseAttributeLines($this->bodyString('htmlAttributes'));
        $group->siteIds = $this->postedSiteIds($request->getBodyParam('siteIds'));

        if (!MenuBuilder::getInstance()->groups->save($group)) {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'Couldn’t save navigation group.'));

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

    /**
     * Normalizes the posted site restriction into `int[]`. Craft's
     * checkbox-select posts a zero-value padding field alongside the checked
     * `[]` entries, and posts a bare string instead of an array when nothing
     * is checked (same shape ItemsController::buildVisibilityRules() guards
     * against for the per-item `site` rule) — both collapse to "no
     * restriction" here.
     *
     * @return int[]
     */
    private function postedSiteIds(mixed $posted): array
    {
        if (!is_array($posted)) {
            return [];
        }

        $siteIds = array_filter(array_map('intval', array_filter($posted, 'is_scalar')), fn(int $id) => $id > 0);

        return array_values(array_unique($siteIds));
    }

    /** Parses `key: value` per-line input into an attributes array. */
    private function parseAttributeLines(string $input): array
    {
        $attributes = [];

        foreach (explode("\n", $input) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));

            if ($key !== '') {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    private function bodyString(string $name, string $default = ''): string
    {
        $value = Craft::$app->getRequest()->getBodyParam($name, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}

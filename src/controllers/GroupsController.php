<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use Tahadudhiya\MenuBuilder\elements\MenuBuilderItemContent;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\services\MenuBuilderMenuLimitService;
use yii\base\Action;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class GroupsController extends BaseMenuBuilderController
{
    protected function requiredPermission(Action $action): string
    {
        return self::requiredPermissionForAction($action->id);
    }

    /**
     * Pure mapping from action to the permission it requires, factored out so it's unit-testable
     * without a booted Craft app.
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
            return $this->asFailure(Craft::t('menu-builder', 'That menu no longer exists.'));
        }

        $group->enabled = !$group->enabled;
        $success = MenuBuilder::getInstance()->groups->save($group, runValidation: false);

        return $this->respondToMutation(
            $success,
            Craft::t('menu-builder', 'Couldn’t update that menu.'),
            data: ['enabled' => $group->enabled],
            successMessage: $group->enabled
                ? Craft::t('menu-builder', 'Menu enabled.')
                : Craft::t('menu-builder', 'Menu disabled.'),
        );
    }

    /**
     * Drag-and-drop / keyboard reorder endpoint for the menus list, the counterpart to
     * `ItemsController::actionReorder()`.
     *
     * Menus are flat, so there is no parent to validate and no depth to check: the whole request is
     * one ordered list of ids, and the service reconciles it against the menus that actually exist.
    */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();

        $ids = $this->bodyArray('ids');
        $ids = array_values(array_map('intval', array_filter($ids, 'is_scalar')));

        $success = MenuBuilder::getInstance()->groups->reorder($ids);

        return $this->respondToMutation(
            $success,
            Craft::t('menu-builder', 'Couldn’t reorder the menus.'),
            successMessage: Craft::t('menu-builder', 'Menus reordered.'),
        );
    }

    public function actionIndex(): Response
    {
        $groups = MenuBuilder::getInstance()->groups->getAll();

        $rows = array_map(fn(MenuBuilderGroup $group) => [
            'group' => $group,
            'itemCount' => MenuBuilder::getInstance()->groups->countItems($group->id),
        ], $groups);

        return $this->renderTemplate('menu-builder/groups/_index', [
            'rows' => $rows,
            // One call, one shape — see MenuBuilderMenuLimitService::cpSummary().
            'edition' => MenuBuilder::getInstance()->menuLimit->cpSummary(),
        ] + $this->currentUserAffordances());
    }

    public function actionEdit(?int $groupId = null, ?MenuBuilderGroup $group = null): Response
    {
        if ($group === null) {
            if ($groupId !== null) {
                $group = MenuBuilder::getInstance()->groups->getById($groupId);

                if (!$group) {
                    throw new NotFoundHttpException('Menu not found.');
                }
            } else {
                // A new menu the edition can't hold: say so where the count and the upgrade link
                // already are, rather than rendering a form whose save the service would refuse.
                if (!MenuBuilder::getInstance()->menuLimit->canCreateMenu()) {
                    Craft::$app->getSession()->setError(MenuBuilderMenuLimitService::limitMessage());

                    return $this->redirect(UrlHelper::cpUrl('menu-builder'));
                }

                $group = new MenuBuilderGroup();
            }
        }

        // `edit` only needs `view`, so this form is reachable read-only.
        return $this->renderTemplate('menu-builder/groups/_edit', [
            'group' => $group,
            'isNew' => $group->id === null,
            'itemCount' => $group->id !== null
                ? MenuBuilder::getInstance()->groups->countItems($group->id)
                : 0,
        ] + $this->currentUserAffordances());
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $groupId = (int)$request->getBodyParam('groupId', 0);
        $group = $groupId ? MenuBuilder::getInstance()->groups->getById($groupId) : new MenuBuilderGroup();

        if (!$group) {
            throw new NotFoundHttpException('Menu not found.');
        }

        // Asked before the posted values are mapped so the answer is the upgrade message rather
        // than "couldn’t save that menu" with a field error attached to a name that isn’t the
        // problem.
        if ($group->id === null && !MenuBuilder::getInstance()->menuLimit->canCreateMenu()) {
            return $this->asFailure(MenuBuilderMenuLimitService::limitMessage());
        }

        $group->name = $this->bodyString('name');
        $group->handle = $this->bodyString('handle');
        $description = $request->getBodyParam('description');
        $group->description = is_scalar($description) ? (string)$description : null;
        $group->enabled = (bool)$request->getBodyParam('enabled', false);
        $group->cssClass = $this->bodyString('cssClass') ?: null;
        $group->maxDepth = $this->bodyIntOrNull('maxDepth');
        $group->htmlAttributes = LinkAttributeHelper::parseAttributeLines($this->bodyString('htmlAttributes'));
        $group->siteIds = ConfigHelper::normalizeIdList($request->getBodyParam('siteIds'));
        // Craft assembles the field layout from the designer's own posted payload — this plugin
        // neither parses nor validates its contents, which is the whole point of using the real
        // designer: the tabs, the field settings, the conditions and the element condition rules
        // are Craft's grammar, not a second one to keep in step.
        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->id = $group->fieldLayoutId;
        $fieldLayout->type = MenuBuilderItemContent::class;
        $group->setFieldLayout($fieldLayout);

        if (!MenuBuilder::getInstance()->groups->save($group)) {
            // asModelFailure() sets the error flash itself — setting one here as well surfaced
            // the same message twice in the CP.
            return $this->asModelFailure($group, Craft::t('menu-builder', 'Couldn’t save that menu.'), 'group');
        }

        Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Menu saved.'));

        return $this->redirectToPostedUrl($group, UrlHelper::cpUrl('menu-builder/' . $group->handle));
    }


    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        // Duplicating creates a menu, so it meets the same ceiling.
        if (!MenuBuilder::getInstance()->menuLimit->canCreateMenu()) {
            return $this->asFailure(MenuBuilderMenuLimitService::limitMessage());
        }

        $clone = MenuBuilder::getInstance()->groups->duplicate($id);

        if ($clone === null) {
            return $this->asFailure(Craft::t('menu-builder', 'Couldn’t duplicate that menu.'));
        }

        // The message matters on the non-JSON path: the edit screen's Duplicate is a form action
        // now, so it posts and redirects like an ordinary save and would otherwise land on a
        // generic flash.
        return $this->asSuccess(Craft::t('menu-builder', 'Menu duplicated.'), data: [
            'id' => $clone->id,
            'url' => UrlHelper::cpUrl('menu-builder/' . $clone->handle),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $success = MenuBuilder::getInstance()->groups->deleteById($id);

        // The index, not the posted redirect: the screen this was posted
        // from may well have been the deleted menu's own.
        return $this->respondToMutation(
            $success,
            Craft::t('menu-builder', 'Couldn’t delete that menu.'),
            successMessage: Craft::t('menu-builder', 'Menu deleted.'),
            redirectUrl: UrlHelper::cpUrl('menu-builder'),
        );
    }
}

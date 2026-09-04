<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\helpers\CustomFieldHelper;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderCustomField;
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

    protected function permissionDeniedMessage(): string
    {
        return 'You are not permitted to manage navigation menus.';
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
            return $this->asFailure(Craft::t('menu-builder', 'That menu no longer exists.'));
        }

        $group->enabled = !$group->enabled;
        $success = MenuBuilder::getInstance()->groups->save($group, runValidation: false);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $success
                ? $this->asSuccess(data: ['enabled' => $group->enabled])
                : $this->asFailure(Craft::t('menu-builder', 'Couldn’t update that menu.'));
        }

        if ($success) {
            Craft::$app->getSession()->setSuccess($group->enabled
                ? Craft::t('menu-builder', 'Menu enabled.')
                : Craft::t('menu-builder', 'Menu disabled.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'Couldn’t update that menu.'));
        }

        return $this->redirectToPostedUrl();
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
            // The index is the plugin's only CP destination (the nav item has
            // no subnav), so this is where the edition is stated.
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
                // A new menu the edition can't hold: say so where the count
                // and the upgrade link already are, rather than rendering a
                // form whose save the service would refuse. This is a
                // courtesy, not the gate — see MenuBuilderGroupService::save().
                if (!MenuBuilder::getInstance()->menuLimit->canCreateMenu()) {
                    Craft::$app->getSession()->setError(MenuBuilderMenuLimitService::limitMessage());

                    return $this->redirect(UrlHelper::cpUrl('menu-builder'));
                }

                $group = new MenuBuilderGroup();
            }
        }

        // `edit` only needs `view`, so this form is reachable read-only. The
        // template hides Save/Delete accordingly rather than offering buttons
        // the save/delete actions would then refuse.
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

        // Asked before the posted values are mapped so the answer is the
        // upgrade message rather than "couldn’t save that menu" with a
        // field error attached to a name that isn’t the problem. The save
        // itself is refused by the service either way, including for a
        // request that never came through this action.
        if ($group->id === null && !MenuBuilder::getInstance()->menuLimit->canCreateMenu()) {
            return $this->asFailure(MenuBuilderMenuLimitService::limitMessage());
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
        $group->customFields = $this->buildCustomFields($this->bodyArray('customFields'));

        if (!MenuBuilder::getInstance()->groups->save($group)) {
            // asModelFailure() sets the error flash itself — setting one
            // here as well surfaced the same message twice in the CP.
            return $this->asModelFailure($group, Craft::t('menu-builder', 'Couldn’t save that menu.'), 'group');
        }

        Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Menu saved.'));

        return $this->redirectToPostedUrl($group, UrlHelper::cpUrl('menu-builder/' . $group->handle));
    }

    /**
     * Builds the menu's custom field definitions from the editable table's
     * posted rows.
     *
     * Rows are mapped, never trusted: each becomes a
     * {@see MenuBuilderCustomField}, which validates itself, and the set is
     * then checked for duplicate handles and the per-menu ceiling by
     * MenuBuilderGroup::validateCustomFields(). Completely blank rows are
     * dropped rather than reported — Craft's editable table always posts a
     * trailing empty row.
     *
     * @param array<mixed,mixed> $rows
     * @return MenuBuilderCustomField[]
     */
    private function buildCustomFields(array $rows): array
    {
        $definitions = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $handle = trim((string)($this->scalarOrEmpty($row['handle'] ?? null)));
            $name = trim((string)($this->scalarOrEmpty($row['name'] ?? null)));

            if ($handle === '' && $name === '') {
                continue;
            }

            $field = new MenuBuilderCustomField();
            $field->handle = $handle;
            $field->name = $name;
            $field->type = $this->scalarOrEmpty($row['type'] ?? null);
            $field->instructions = $this->scalarOrEmpty($row['instructions'] ?? null) ?: null;
            $field->required = ($row['required'] ?? false) === true || ($row['required'] ?? null) === '1';
            // One comma-separated cell rather than a nested table: the
            // options list is a short allowlist, and CustomFieldHelper
            // trims, de-duplicates and drops the empties.
            $field->options = CustomFieldHelper::normalizeOptions(explode(',', $this->scalarOrEmpty($row['options'] ?? null)));

            $definitions[] = $field;
        }

        return $definitions;
    }

    private function scalarOrEmpty(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        // Duplicating creates a menu, so it meets the same ceiling. Asked
        // here as well as in the service so the answer can be the reason
        // rather than a generic "couldn’t duplicate".
        if (!MenuBuilder::getInstance()->menuLimit->canCreateMenu()) {
            return $this->asFailure(MenuBuilderMenuLimitService::limitMessage());
        }

        $clone = MenuBuilder::getInstance()->groups->duplicate($id);

        if ($clone === null) {
            return $this->asFailure(Craft::t('menu-builder', 'Couldn’t duplicate that menu.'));
        }

        // The message matters on the non-JSON path: the edit screen's
        // Duplicate is a form action now, so it posts and redirects like an
        // ordinary save and would otherwise land on a generic flash.
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

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $success
                ? $this->asSuccess()
                : $this->asFailure(Craft::t('menu-builder', 'Couldn’t delete that menu.'));
        }

        if ($success) {
            Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Menu deleted.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'Couldn’t delete that menu.'));
        }

        return $this->redirect(UrlHelper::cpUrl('menu-builder'));
    }
}

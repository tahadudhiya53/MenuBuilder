<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ItemsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $currentUser = Craft::$app->getUser()->getIdentity();
        $isNewSave = $action->id === 'save' && (int)Craft::$app->getRequest()->getBodyParam('itemId', 0) === 0;
        $bulkOp = $action->id === 'bulk' ? $this->bodyString('op') : null;
        $requiredPermission = self::requiredPermissionForAction($action->id, $isNewSave, $bulkOp);

        if (!$currentUser || (!$currentUser->admin && !$currentUser->can($requiredPermission))) {
            throw new ForbiddenHttpException('You are not permitted to manage navigation menus.');
        }

        return true;
    }

    /**
     * Pure mapping from action (+ whether a `save` is creating a brand new
     * item) to the permission it requires — factored out from
     * beforeAction() so it's unit-testable without a booted Craft app or
     * request. `duplicate` always creates a new item, so it always needs
     * `create` regardless of which item is being cloned; `toggle`/`reorder`
     * only ever act on an item that already exists, so they need `edit`.
     */
    public static function requiredPermissionForAction(string $actionId, bool $isNewSave, ?string $bulkOp = null): string
    {
        return match ($actionId) {
            'delete' => 'menuBuilder:delete',
            'edit' => 'menuBuilder:view',
            'duplicate' => 'menuBuilder:create',
            'save' => $isNewSave ? 'menuBuilder:create' : 'menuBuilder:edit',
            // A bulk op needs whatever permission its single-item equivalent
            // needs — 'delete' is the only op that needs more than 'edit'.
            'bulk' => $bulkOp === 'delete' ? 'menuBuilder:delete' : 'menuBuilder:edit',
            default => 'menuBuilder:edit',
        };
    }

    public function actionEdit(string $groupHandle, ?int $itemId = null, ?MenuBuilderItem $item = null): Response
    {
        $group = MenuBuilder::getInstance()->groups->getByHandle($groupHandle);

        if (!$group) {
            throw new NotFoundHttpException('Navigation group not found.');
        }

        // Edit-only: creating an item happens in exactly one place, the
        // dashboard's quick-add panel (which posts straight to actionSave()).
        // There is deliberately no second "new item" form here — see
        // ARCHITECTURE.md, "Single path per behaviour".
        if ($item === null) {
            if ($itemId === null) {
                throw new NotFoundHttpException('Navigation menu not found.');
            }

            $item = MenuBuilder::getInstance()->items->getById($itemId);

            if (!$item || $item->groupId !== $group->id) {
                throw new NotFoundHttpException('Navigation menu not found.');
            }
        }

        $variables = [
            'group' => $group,
            'item' => $item,
            'isNew' => $item->id === null,
            'siblingCandidates' => MenuBuilder::getInstance()->items->getFlatForGroup($group->id),
        ];

        if (Craft::$app->getRequest()->getIsAjax() && Craft::$app->getRequest()->getAcceptsJson()) {
            $view = $this->getView();
            $html = $view->renderTemplate('menu-builder/items/_fields', $variables);

            return $this->asJson([
                'title' => $variables['isNew'] ? Craft::t('menu-builder', 'New menu item') : $item->title,
                'html' => $html,
                'headHtml' => $view->getHeadHtml(),
                'footHtml' => $view->getBodyHtml(),
            ]);
        }

        return $this->renderTemplate('menu-builder/items/_edit', $variables);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $itemId = (int)$request->getBodyParam('itemId', 0);
        $item = $itemId ? MenuBuilder::getInstance()->items->getById($itemId) : new MenuBuilderItem();

        if (!$item) {
            throw new NotFoundHttpException('Navigation menu not found.');
        }

        // An existing item's groupId is fixed at creation (see
        // MenuBuilderItemService::isGroupChangeAllowed()) — the posted
        // `groupId` is a hidden field and must not be trusted to move an
        // existing item into a different group. Only a brand new item may
        // pick its group from the posted value.
        $postedGroupId = (int)$request->getRequiredBodyParam('groupId');
        $item->groupId = $item->id !== null ? $item->groupId : $postedGroupId;
        $parentId = $request->getBodyParam('parentId');
        $item->parentId = ($parentId !== null && $parentId !== '') ? (int)$parentId : null;
        $item->type = $this->bodyString('type', MenuBuilderItem::TYPE_URL);
        $item->title = $this->bodyString('title');
        $item->handle = $this->bodyString('handle') ?: null;
        // The "Enabled" toggle only exists once an item can be saved a second
        // time (basic-only new-item forms don't show it — see spec §12/isNew
        // handling in items/_fields.twig), so a missing param means "leave
        // the default" rather than "explicitly disabled".
        $item->enabled = (bool)$request->getBodyParam('enabled', $item->id === null);
        $item->clickable = (bool)$request->getBodyParam('clickable', false);

        $elementId = $request->getBodyParam('elementId');
        $item->elementId = ($elementId !== null && $elementId !== '') ? (int)$elementId : null;

        $item->customUrl = $this->bodyString('customUrl') ?: null;
        $item->target = $this->bodyString('target', '_self');
        $item->rel = $this->buildRel($request);

        $item->cssClass = $this->bodyString('cssClass') ?: null;
        $item->htmlId = $this->bodyString('htmlId') ?: null;
        $item->htmlAttributes = $this->parseAttributeLines($this->bodyString('htmlAttributes'));
        $item->ariaLabel = $this->bodyString('ariaLabel') ?: null;
        $item->titleAttribute = $this->bodyString('titleAttribute') ?: null;
        $item->icon = $this->bodyString('icon') ?: null;
        $item->badge = $this->bodyString('badge') ?: null;
        $item->description = $this->bodyString('description') ?: null;

        $image = $request->getBodyParam('image');
        $item->image = ($image !== null && $image !== '') ? (int)$image : null;
        $item->featured = (bool)$request->getBodyParam('featured', false);

        $item->fallbackBehavior = $this->bodyString('fallbackBehavior', MenuBuilderItem::FALLBACK_HIDE);
        $item->fallbackUrl = $this->bodyString('fallbackUrl') ?: null;

        $item->visibility = $this->buildVisibilityRules($this->bodyArray('visibility'));
        $item->metadata = $this->buildMetadata($request, $item->type);

        if (!MenuBuilder::getInstance()->items->save($item)) {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'Couldn’t save navigation menu.'));

            return $this->asModelFailure($item, Craft::t('menu-builder', 'Couldn’t save navigation menu.'), 'item');
        }

        Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Navigation menu saved.'));

        $group = MenuBuilder::getInstance()->groups->getById($item->groupId);

        if ($request->getIsAjax() && $request->getAcceptsJson()) {
            return $this->asSuccess(data: ['id' => $item->id, 'title' => $item->title]);
        }

        return $this->redirectToPostedUrl($item, UrlHelper::cpUrl('menu-builder/' . $group?->handle));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $id = (int)$request->getRequiredBodyParam('id');
        $itemsService = MenuBuilder::getInstance()->items;
        $keepChildrenParam = $request->getBodyParam('keepChildren');
        $wantsChoice = $request->getIsAjax() && $request->getAcceptsJson();

        if ($keepChildrenParam === null && $wantsChoice && $itemsService->hasChildren($id)) {
            return $this->asJson([
                'requiresChoice' => true,
                'childCount' => $itemsService->countDirectChildren($id),
            ]);
        }

        $success = $itemsService->deleteById($id, (bool)$keepChildrenParam);

        return $this->asJsonResult($success, Craft::t('menu-builder', 'Couldn’t delete that menu.'));
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $clone = MenuBuilder::getInstance()->items->duplicate($id);

        if ($clone === null) {
            return $this->asFailure(Craft::t('menu-builder', 'Couldn’t duplicate that menu.'));
        }

        return $this->asSuccess(data: ['id' => $clone->id]);
    }

    public function actionToggle(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $item = MenuBuilder::getInstance()->items->getById($id);

        if (!$item) {
            return $this->asFailure(Craft::t('menu-builder', 'Navigation menu not found.'));
        }

        $item->enabled = !$item->enabled;
        $success = MenuBuilder::getInstance()->items->save($item, runValidation: false);

        return $this->asJsonResult($success, Craft::t('menu-builder', 'Couldn’t update that menu.'), ['enabled' => $item->enabled]);
    }

    /**
     * Drag-and-drop / keyboard reorder endpoint. Body:
     * `groupId`, `itemId`, `newParentId` (nullable), `siblingIds` (ordered array
     * including itemId, for the new parent). Re-validates depth/circularity/
     * cross-group server-side regardless of the client's own checks.
     */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $itemId = (int)$request->getRequiredBodyParam('itemId');
        $groupId = (int)$request->getRequiredBodyParam('groupId');
        $newParentId = $request->getBodyParam('newParentId');
        $newParentId = ($newParentId !== null && $newParentId !== '') ? (int)$newParentId : null;
        $siblingIds = $request->getBodyParam('siblingIds', []);
        $siblingIds = is_array($siblingIds) ? array_map('intval', $siblingIds) : [];

        $newSortOrder = array_search($itemId, $siblingIds, true);
        $newSortOrder = $newSortOrder === false ? count($siblingIds) : $newSortOrder;

        $itemsService = MenuBuilder::getInstance()->items;

        if (!$itemsService->move($itemId, $newParentId, $newSortOrder)) {
            return $this->asFailure(Craft::t('menu-builder', 'That move isn’t allowed.'));
        }

        if (!empty($siblingIds)) {
            $itemsService->reorderSiblings($groupId, $newParentId, $siblingIds);
        }

        return $this->asSuccess();
    }

    /**
     * Phase 11 bulk action endpoint. Body: `op` (enable|disable|delete),
     * `ids[]`. Every posted ID is still individually validated/permission-
     * scoped by MenuBuilderItemService (see beforeAction()'s per-op
     * permission mapping) — the CP's multi-select checkboxes are UX only.
     */
    public function actionBulk(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $op = $this->bodyString('op');
        $ids = array_filter(array_map('intval', $this->bodyArray('ids')));

        if (empty($ids)) {
            return $this->asFailure(Craft::t('menu-builder', 'No menu items were selected.'));
        }

        $itemsService = MenuBuilder::getInstance()->items;

        $success = match ($op) {
            'enable' => $itemsService->bulkSetEnabled($ids, true),
            'disable' => $itemsService->bulkSetEnabled($ids, false),
            'delete' => $itemsService->bulkDelete($ids),
            default => false,
        };

        return $this->asJsonResult($success, Craft::t('menu-builder', 'That bulk action couldn’t be completed.'));
    }

    private function asJsonResult(bool $success, string $failureMessage, array $extraData = []): Response
    {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $success ? $this->asSuccess(data: $extraData) : $this->asFailure($failureMessage);
        }

        if ($success) {
            Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Saved.'));
        } else {
            Craft::$app->getSession()->setError($failureMessage);
        }

        return $this->redirectToPostedUrl();
    }

    private function buildRel(\craft\web\Request $request): ?string
    {
        $parts = [];

        if ($request->getBodyParam('nofollow')) {
            $parts[] = 'nofollow';
        }

        if ($request->getBodyParam('sponsored')) {
            $parts[] = 'sponsored';
        }

        $custom = $this->bodyString('rel');

        if ($custom) {
            $parts[] = $custom;
        }

        return !empty($parts) ? implode(' ', array_unique($parts)) : null;
    }

    /**
     * Builds the visibility rule-config array from the edit form's discrete
     * fields (checkboxes/selects) rather than expecting the editor to author
     * JSON directly — see MenuBuilderVisibilityService for how each rule type
     * is evaluated.
     */
    private function buildVisibilityRules(array $posted): array
    {
        $rules = [];

        if (!empty($posted['loggedIn'])) {
            $rules[] = ['type' => 'loggedIn'];
        }

        if (!empty($posted['loggedOut'])) {
            $rules[] = ['type' => 'loggedOut'];
        }

        // Checkbox-select groups post a zero-value padding field alongside
        // the checked `[]` entries so the key always exists — when nothing
        // is checked, that padding value arrives as a bare string instead
        // of an array (seen from the AJAX slide-out's own request builder).
        $userGroups = array_filter(array_map('intval', is_array($posted['userGroups'] ?? null) ? $posted['userGroups'] : []));
        if (!empty($userGroups)) {
            $rules[] = ['type' => 'userGroup', 'groupIds' => array_values($userGroups)];
        }

        $sites = array_filter(array_map('intval', is_array($posted['sites'] ?? null) ? $posted['sites'] : []));
        if (!empty($sites)) {
            $rules[] = ['type' => 'site', 'siteIds' => array_values($sites)];
        }

        if (!empty($posted['dateStart']) || !empty($posted['dateEnd'])) {
            $rules[] = array_filter([
                'type' => 'dateRange',
                'start' => $posted['dateStart'] ?? null,
                'end' => $posted['dateEnd'] ?? null,
            ]);
        }

        $environments = array_filter(array_map('trim', explode(',', $posted['environments'] ?? '')));
        if (!empty($environments)) {
            $rules[] = ['type' => 'environment', 'environments' => array_values($environments)];
        }

        return $rules;
    }

    /**
     * Builds the `metadata` bag from discrete, explicitly-named POST fields
     * (Phases 6-8) rather than trusting a raw posted `metadata` array
     * directly — an uncontrolled `metadata[...]` POST key would otherwise
     * let a tampered request inject arbitrary data into a bag that's
     * rendered/read by Twig (spec: "Do not allow arbitrary uncontrolled
     * POST keys"). `MenuBuilderItem::validate*()` re-validates all of this
     * server-side regardless.
     */
    private function buildMetadata(\craft\web\Request $request, string $itemType): array
    {
        $metadata = [];

        if ((bool)$request->getBodyParam('megaMenuEnabled', false)) {
            $columns = (int)$request->getBodyParam('megaMenuColumns', 1);
            $metadata['megaMenu'] = ['enabled' => true, 'columns' => max(1, min(6, $columns))];
        }

        $column = $request->getBodyParam('megaMenuColumn');
        if ($column !== null && $column !== '') {
            $metadata['megaMenuColumn'] = max(1, min(6, (int)$column));
        }

        if ($itemType === MenuBuilderItem::TYPE_DYNAMIC) {
            $sourceId = $request->getBodyParam('dynamicSourceId');
            $limit = $request->getBodyParam('dynamicSourceLimit');

            $metadata['dynamicSource'] = array_filter([
                'sourceType' => $this->bodyString('dynamicSourceType') ?: null,
                'sourceId' => ($sourceId !== null && $sourceId !== '') ? (int)$sourceId : null,
                'limit' => ($limit !== null && $limit !== '') ? min((int)$limit, MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT) : null,
                'orderBy' => $this->bodyString('dynamicSourceOrderBy') ?: null,
            ], fn($value) => $value !== null);
        }

        return $metadata;
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

    private function bodyArray(string $name): array
    {
        $value = Craft::$app->getRequest()->getBodyParam($name, []);

        return is_array($value) ? $value : [];
    }

    private function bodyString(string $name, string $default = ''): string
    {
        $value = Craft::$app->getRequest()->getBodyParam($name, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}

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
        $requiredPermission = match ($action->id) {
            'delete' => 'menuBuilder:delete',
            'edit' => 'menuBuilder:view',
            default => 'menuBuilder:edit',
        };

        if (!$currentUser || (!$currentUser->admin && !$currentUser->can($requiredPermission))) {
            throw new ForbiddenHttpException('You are not permitted to manage navigation menus.');
        }

        return true;
    }

    public function actionEdit(string $groupHandle, ?int $itemId = null, ?MenuBuilderItem $item = null): Response
    {
        $group = MenuBuilder::getInstance()->groups->getByHandle($groupHandle);

        if (!$group) {
            throw new NotFoundHttpException('Navigation group not found.');
        }

        if ($item === null) {
            if ($itemId !== null) {
                $item = MenuBuilder::getInstance()->items->getById($itemId);

                if (!$item || $item->groupId !== $group->id) {
                    throw new NotFoundHttpException('Navigation menu not found.');
                }
            } else {
                $item = new MenuBuilderItem();
                $item->groupId = $group->id;
                $parentId = Craft::$app->getRequest()->getQueryParam('parentId');
                $item->parentId = $parentId ? (int)$parentId : null;
            }
        }

        return $this->renderTemplate('menu-builder/items/_edit', [
            'group' => $group,
            'item' => $item,
            'isNew' => $item->id === null,
            'siblingCandidates' => MenuBuilder::getInstance()->items->getFlatForGroup($group->id),
        ]);
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

        $item->groupId = (int)$request->getRequiredBodyParam('groupId');
        $parentId = $request->getBodyParam('parentId');
        $item->parentId = ($parentId !== null && $parentId !== '') ? (int)$parentId : null;
        $item->type = $this->bodyString('type', MenuBuilderItem::TYPE_URL);
        $item->title = $this->bodyString('title');
        $item->handle = $this->bodyString('handle') ?: null;
        $item->enabled = (bool)$request->getBodyParam('enabled', false);
        $item->clickable = (bool)$request->getBodyParam('clickable', false);

        $elementId = $this->bodyArray('elementId');
        $item->elementId = !empty($elementId) ? (int)reset($elementId) : null;

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

        $image = $this->bodyArray('image');
        $item->image = !empty($image) ? (int)reset($image) : null;
        $item->featured = (bool)$request->getBodyParam('featured', false);

        $item->fallbackBehavior = $this->bodyString('fallbackBehavior', MenuBuilderItem::FALLBACK_HIDE);
        $item->fallbackUrl = $this->bodyString('fallbackUrl') ?: null;

        $item->visibility = $this->buildVisibilityRules($this->bodyArray('visibility'));
        $item->metadata = $this->bodyArray('metadata');

        if (!MenuBuilder::getInstance()->items->save($item)) {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'Couldn’t save navigation menu.'));

            return $this->asModelFailure($item, Craft::t('menu-builder', 'Couldn’t save navigation menu.'), 'item');
        }

        Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Navigation menu saved.'));

        $group = MenuBuilder::getInstance()->groups->getById($item->groupId);

        return $this->redirectToPostedUrl($item, UrlHelper::cpUrl('menu-builder/' . $group?->handle));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $success = MenuBuilder::getInstance()->items->deleteById($id);

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

        $userGroups = array_filter(array_map('intval', $posted['userGroups'] ?? []));
        if (!empty($userGroups)) {
            $rules[] = ['type' => 'userGroup', 'groupIds' => array_values($userGroups)];
        }

        $sites = array_filter(array_map('intval', $posted['sites'] ?? []));
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

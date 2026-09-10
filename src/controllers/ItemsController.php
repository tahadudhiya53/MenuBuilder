<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use Tahadudhiya\MenuBuilder\helpers\BadgeHelper;
use Tahadudhiya\MenuBuilder\helpers\IconHelper;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\helpers\MobileHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use yii\base\Action;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ItemsController extends BaseMenuBuilderController
{
    protected array|bool|int $allowAnonymous = false;

    protected function requiredPermission(Action $action): string
    {
        $isNewSave = $action->id === 'save' && (int)Craft::$app->getRequest()->getBodyParam('itemId', 0) === 0;
        $bulkOp = $action->id === 'bulk' ? $this->bodyString('op') : null;

        return self::requiredPermissionForAction($action->id, $isNewSave, $bulkOp);
    }

    /**
     * Pure mapping from action (+ whether a `save` is creating a brand new item) to the permission
     * it requires — factored out from beforeAction() so it's unit-testable without a booted Craft
     * app or request.
    */
    public static function requiredPermissionForAction(string $actionId, bool $isNewSave, ?string $bulkOp = null): string
    {
        return match ($actionId) {
            'delete' => 'menuBuilder:delete',
            'edit' => 'menuBuilder:view',
            'duplicate' => 'menuBuilder:create',
            'save' => $isNewSave ? 'menuBuilder:create' : 'menuBuilder:edit',
            // A bulk op needs whatever permission its single-item equivalent needs — 'delete' is
            // the only op that needs more than 'edit'.
            'bulk' => $bulkOp === 'delete' ? 'menuBuilder:delete' : 'menuBuilder:edit',
            default => 'menuBuilder:edit',
        };
    }

    public function actionEdit(string $groupHandle, ?int $itemId = null, ?MenuBuilderItem $item = null): Response
    {
        $group = MenuBuilder::getInstance()->groups->getByHandle($groupHandle);

        if (!$group) {
            throw new NotFoundHttpException('Menu not found.');
        }

        // Edit-only: creating an item happens in exactly one place, the dashboard's quick-add panel
        // (which posts straight to actionSave()).
        if ($item === null) {
            if ($itemId === null) {
                throw new NotFoundHttpException('Menu item not found.');
            }

            $item = MenuBuilder::getInstance()->items->getById($itemId);

            if (!$item || $item->groupId !== $group->id) {
                throw new NotFoundHttpException('Menu item not found.');
            }
        }

        // `edit` only needs `view` (this action renders a form, it doesn't save one), so both
        // wrappers around items/_fields have to be able to render read-only rather than offer a
        // Save the save action refuses.
        $affordances = $this->currentUserAffordances();

        $variables = [
            'group' => $group,
            'item' => $item,
            'isNew' => $item->id === null,
            'siblingCandidates' => MenuBuilder::getInstance()->items->getFlatForGroup($group->id),
            // Why this item's link would or wouldn't work, shown at the top of the editor.
            'health' => $item->id !== null
                ? MenuBuilder::getInstance()->linkHealth->getForItem($item)
                : null,
            // The dynamic-source cap belongs to the model; the editor's field shows and bounds it
            // rather than writing the number out again.
            'dynamicSourceMaxLimit' => MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT,
        ] + $affordances;

        if (Craft::$app->getRequest()->getIsAjax() && Craft::$app->getRequest()->getAcceptsJson()) {
            $view = $this->getView();
            $html = $view->renderTemplate('menu-builder/items/_fields', $variables);

            return $this->asJson([
                'title' => $variables['isNew'] ? Craft::t('menu-builder', 'New menu item') : $this->itemLabel($item),
                'html' => $html,
                'headHtml' => $view->getHeadHtml(),
                'footHtml' => $view->getBodyHtml(),
                // The slide-out's Save button is the one control it renders itself, so it can't be
                // hidden by the template above.
                'canSave' => $affordances['canEdit'],
            ]);
        }

        return $this->renderTemplate('menu-builder/items/_edit', $variables);
    }

    /**
     * What to call an item on screen.
    */
    private function itemLabel(MenuBuilderItem $item): string
    {
        $title = trim((string)$item->title);

        return $title !== '' ? $title : Craft::t('menu-builder', '(untitled)');
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $itemId = (int)$request->getBodyParam('itemId', 0);
        $item = $itemId ? MenuBuilder::getInstance()->items->getById($itemId) : new MenuBuilderItem();

        if (!$item) {
            throw new NotFoundHttpException('Menu item not found.');
        }

        // An existing item's groupId is fixed at creation (see
        // MenuBuilderItemService::isGroupChangeAllowed()) — the posted `groupId` is a hidden
        // field and must not be trusted to move an existing item into a different group.
        $postedGroupId = (int)$request->getRequiredBodyParam('groupId');
        $item->groupId = $item->id !== null ? $item->groupId : $postedGroupId;
        $item->parentId = $this->bodyIntOrNull('parentId');
        $item->type = $this->bodyString('type', MenuBuilderItem::TYPE_URL);
        $item->title = $this->bodyString('title');
        $item->handle = $this->bodyString('handle') ?: null;
        // The "Enabled" toggle only exists once an item can be saved a second time (basic-only
        // new-item forms don't show it — see the isNew handling in items/_fields.twig), so a
        // missing param means "leave the default" rather than "explicitly disabled".
        $item->enabled = (bool)$request->getBodyParam('enabled', $item->id === null);
        $item->clickable = (bool)$request->getBodyParam('clickable', false);

        $item->elementId = $this->bodyIntOrNull('elementId');

        $item->customUrl = $this->bodyString('customUrl') ?: null;
        $item->target = $this->bodyString('target', '_self');
        $item->rel = $this->buildRel($request);

        $item->cssClass = $this->bodyString('cssClass') ?: null;
        $item->htmlId = $this->bodyString('htmlId') ?: null;
        $item->htmlAttributes = LinkAttributeHelper::parseAttributeLines($this->bodyString('htmlAttributes'));
        $item->ariaLabel = $this->bodyString('ariaLabel') ?: null;
        $item->titleAttribute = $this->bodyString('titleAttribute') ?: null;
        // Three inputs, one column: the icon source select decides which of the two fields is read
        // (see IconHelper::composeFromForm()), so a stale value left in the hidden one can't win.
        $item->icon = IconHelper::composeFromForm(
            $this->bodyString('iconSource'),
            $this->bodyString('icon'),
            $request->getBodyParam('iconAsset'),
        );
        // Text only — the badge's style is a closed enum and rides in metadata with the other
        // presentation config (see buildMetadata()).
        $item->badge = BadgeHelper::normalizeText($this->bodyString('badge'));
        $item->description = $this->bodyString('description') ?: null;

        $item->image = $this->bodyIntOrNull('image');
        $item->featured = (bool)$request->getBodyParam('featured', false);

        $item->fallbackBehavior = $this->bodyString('fallbackBehavior', MenuBuilderItem::FALLBACK_HIDE);
        $item->fallbackUrl = $this->bodyString('fallbackUrl') ?: null;

        $item->visibility = $this->buildVisibilityRules($this->bodyArray('visibility'));
        $item->metadata = $this->buildMetadata($request, $item->type);

        // Custom field content is Craft's to read off the request: the fields in the menu's layout
        // own their own input names, their own normalization and their own validation, so there is
        // no allowlist for this plugin to keep — which is precisely why an arbitrary
        // `fields[...]` key can't inject anything.
        $content = $item->getContent();

        if ($content !== null) {
            $content->setFieldValuesFromRequest('fields');
            $item->setContent($content);
        }

        if (!MenuBuilder::getInstance()->items->save($item)) {
            // asModelFailure() sets the error flash itself (and returns the field errors to the
            // slide-out) — setting one here as well surfaced the same message twice, once as a
            // flash and once as the notification the JS raises from the response.
            return $this->asModelFailure($item, Craft::t('menu-builder', 'Couldn’t save that menu item.'), 'item');
        }

        $group = MenuBuilder::getInstance()->groups->getById($item->groupId);

        // A flash set before this branch would sit in the session unread and then surface on the
        // *next* full page load — the reload the slide-out triggers after saving — as a second,
        // differently-worded notice about a save the editor had already been told about.
        if ($request->getIsAjax() && $request->getAcceptsJson()) {
            return $this->asSuccess(data: ['id' => $item->id, 'title' => $item->title]);
        }

        Craft::$app->getSession()->setSuccess(Craft::t('menu-builder', 'Menu item saved.'));

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

        return $this->respondToMutation($success, Craft::t('menu-builder', 'Couldn’t delete that menu item.'));
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $clone = MenuBuilder::getInstance()->items->duplicate($id);

        if ($clone === null) {
            return $this->asFailure(Craft::t('menu-builder', 'Couldn’t duplicate that menu item.'));
        }

        return $this->asSuccess(data: ['id' => $clone->id]);
    }

    public function actionToggle(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $item = MenuBuilder::getInstance()->items->getById($id);

        if (!$item) {
            return $this->asFailure(Craft::t('menu-builder', 'That menu item no longer exists.'));
        }

        $item->enabled = !$item->enabled;
        $success = MenuBuilder::getInstance()->items->save($item, runValidation: false);

        return $this->respondToMutation($success, Craft::t('menu-builder', 'Couldn’t update that menu item.'), ['enabled' => $item->enabled]);
    }

    /**
     * Drag-and-drop / keyboard reorder endpoint.
    */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $itemId = (int)$request->getRequiredBodyParam('itemId');
        $groupId = (int)$request->getRequiredBodyParam('groupId');
        $newParentId = $this->bodyIntOrNull('newParentId');
        $siblingIds = $request->getBodyParam('siblingIds', []);
        $siblingIds = is_array($siblingIds) ? array_values(array_map('intval', $siblingIds)) : [];

        $itemsService = MenuBuilder::getInstance()->items;
        $item = $itemsService->getById($itemId);

        if ($item === null) {
            throw new NotFoundHttpException('Menu item not found.');
        }

        // An item's group is fixed at creation.
        if ($item->groupId !== $groupId) {
            return $this->asFailure(Craft::t('menu-builder', 'A navigation menu item cannot be moved to a different navigation group.'));
        }

        $newSortOrder = array_search($itemId, $siblingIds, true);
        $newSortOrder = $newSortOrder === false ? count($siblingIds) : $newSortOrder;

        if (!$itemsService->move($itemId, $newParentId, $newSortOrder, $siblingIds)) {
            return $this->asFailure(
                $itemsService->getLastMoveError() ?? Craft::t('menu-builder', 'That move isn’t allowed.')
            );
        }

        return $this->asSuccess();
    }

    /**
     * Bulk action endpoint.
    */
    public function actionBulk(): Response
    {
        $this->requirePostRequest();

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

        return $this->respondToMutation($success, Craft::t('menu-builder', 'That bulk action couldn’t be completed.'));
    }

    /**
     * The two checkboxes plus the free-text `rel` field, merged into one attribute value.
    */
    private function buildRel(\craft\web\Request $request): ?string
    {
        return LinkAttributeHelper::combineRel([
            $request->getBodyParam('nofollow') ? 'nofollow' : null,
            $request->getBodyParam('sponsored') ? 'sponsored' : null,
            $this->bodyString('rel'),
        ]);
    }

    /**
     * Builds the visibility rule-config array from the edit form's discrete fields
     * (checkboxes/selects) rather than expecting the editor to author JSON directly — see
     * MenuBuilderVisibilityService for how each rule type is evaluated.
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

        // Checkbox-select groups post a zero-value padding field alongside the checked `[]` entries
        // so the key always exists — when nothing is checked, that padding value arrives as a
        // bare string instead of an array (seen from the AJAX slide-out's own request builder).
        $userGroups = array_filter(array_map('intval', is_array($posted['userGroups'] ?? null) ? $posted['userGroups'] : []));
        if (!empty($userGroups)) {
            $rules[] = ['type' => 'userGroup', 'groupIds' => array_values($userGroups)];
        }

        $sites = array_filter(array_map('intval', is_array($posted['sites'] ?? null) ? $posted['sites'] : []));
        if (!empty($sites)) {
            $rules[] = ['type' => 'site', 'siteIds' => array_values($sites)];
        }

        // Scalars only: a tampered `visibility[dateStart][]=x` post would otherwise put an array
        // into a rule config (and `explode()` on the environments field would be an outright
        // TypeError).
        $dateStart = $this->scalarOrNull($posted['dateStart'] ?? null);
        $dateEnd = $this->scalarOrNull($posted['dateEnd'] ?? null);

        if ($dateStart !== null || $dateEnd !== null) {
            $rules[] = array_filter([
                'type' => 'dateRange',
                'start' => $dateStart,
                'end' => $dateEnd,
            ]);
        }

        $environments = array_filter(array_map('trim', explode(',', $this->scalarOrNull($posted['environments'] ?? null) ?? '')));
        if (!empty($environments)) {
            $rules[] = ['type' => 'environment', 'environments' => array_values($environments)];
        }

        return $rules;
    }

    /**
     * Non-empty scalar POST values only — anything else (array, null, '') becomes null.
    */
    private function scalarOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string)$value !== '' ? (string)$value : null;
    }

    /**
     * Builds the `metadata` bag from discrete, explicitly-named POST fields rather than trusting a
     * raw posted `metadata` array directly — an uncontrolled `metadata[...]` POST key would
     * otherwise let a tampered request inject arbitrary data into a bag that's rendered/read by
     * Twig.
    */
    private function buildMetadata(\craft\web\Request $request, string $itemType): array
    {
        $metadata = [];

        if ((bool)$request->getBodyParam('megaMenuEnabled', false)) {
            $columns = (int)$request->getBodyParam('megaMenuColumns', MenuBuilderMegaMenuConfig::MIN_COLUMNS);
            $metadata['megaMenu'] = [
                'enabled' => true,
                'columns' => MenuBuilderMegaMenuConfig::clampColumns($columns),
            ];
        }

        // Only stored alongside actual badge text: a style left behind by a cleared badge would
        // otherwise sit in metadata forever, and come back the next time the editor typed a badge.
        $badgeStyle = BadgeHelper::style($this->bodyString('badgeStyle'));

        if ($badgeStyle !== null && BadgeHelper::hasBadge($this->bodyString('badge'))) {
            $metadata['badgeStyle'] = $badgeStyle;
        }

        $column = $this->bodyIntOrNull('megaMenuColumn');

        if ($column !== null) {
            $metadata['megaMenuColumn'] = MenuBuilderMegaMenuConfig::clampColumns($column);
        }

        // Four discrete fields, one bag key, and nothing stored when they are all at their defaults
        // — see MobileHelper::fromForm().
        $mobile = MobileHelper::fromForm(
            $this->bodyString('mobileVisibility'),
            $request->getBodyParam('mobileOrder'),
            $request->getBodyParam('mobileCollapsible'),
            $this->bodyString('mobileMegaMenu'),
        );

        if ($mobile !== []) {
            $metadata[MobileHelper::METADATA_KEY] = $mobile;
        }

        if ($itemType === MenuBuilderItem::TYPE_DYNAMIC) {
            $limit = $this->bodyIntOrNull('dynamicSourceLimit');

            $metadata['dynamicSource'] = array_filter([
                'sourceType' => $this->bodyString('dynamicSourceType') ?: null,
                'sourceId' => $this->bodyIntOrNull('dynamicSourceId'),
                'limit' => $limit !== null ? min($limit, MenuBuilderItem::DYNAMIC_SOURCE_MAX_LIMIT) : null,
                'orderBy' => $this->bodyString('dynamicSourceOrderBy') ?: null,
            ], fn($value) => $value !== null);
        }

        return $metadata;
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderPreviewOptions;
use Tahadudhiya\MenuBuilder\services\MenuBuilderPreviewService;
use yii\base\Action;
use yii\web\Response;

/**
 * The preview screen: one saved menu, rendered through the production Twig
 * macros for a simulated audience, device and site.
 *
 * Read-only in the strict sense — the only action is a GET that renders a
 * template, and MenuBuilderPreviewService performs no writes — so `view` is
 * the permission it needs, and there is no state-changing request here for
 * CSRF to protect. The controls are a GET form so a particular preview is a
 * shareable URL rather than hidden in a session.
 */
class PreviewController extends BaseMenuBuilderController
{
    /**
     * Preview renders a tree and changes nothing, so `view` covers all of
     * it — the same answer, for the same reason, as the dashboard. Pure
     * static so ControllerPermissionTest can check it without a booted app.
     */
    public static function requiredPermissionForAction(string $actionId): string
    {
        return 'menuBuilder:view';
    }

    protected function requiredPermission(Action $action): string
    {
        return self::requiredPermissionForAction($action->id);
    }

    protected function permissionDeniedMessage(): string
    {
        return 'You are not permitted to view navigation.';
    }

    public function actionIndex(string $groupHandle): Response
    {
        $group = MenuBuilder::getInstance()->groups->getByHandle($groupHandle);

        if (!$group) {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'That navigation menu doesn’t exist.'));

            return $this->redirect(UrlHelper::cpUrl('menu-builder'));
        }

        $preview = MenuBuilder::getInstance()->preview;

        // The allowlists come from the current user, never from the request:
        // MenuBuilderPreviewOptions::normalize() only enforces them.
        $options = MenuBuilderPreviewOptions::normalize(
            Craft::$app->getRequest()->getQueryParams(),
            $preview->allowedSiteIds(),
            $preview->allowedUserGroupIds(),
            (int)Craft::$app->getSites()->getCurrentSite()->id,
        );

        $tree = $preview->getTree($group->handle, $options);
        $nodes = $tree?->items ?? [];

        $items = MenuBuilder::getInstance()->items->getFlatForGroup($group->id, includeDisabled: true);
        $enabledCount = count(array_filter($items, static fn($item): bool => $item->enabled));

        $site = $options->siteId !== null ? Craft::$app->getSites()->getSiteById($options->siteId) : null;
        $site ??= Craft::$app->getSites()->getCurrentSite();

        return $this->renderTemplate('menu-builder/preview/index', [
            'groups' => MenuBuilder::getInstance()->groups->getAll(),
            // The service itself, so the markup panel can re-indent the
            // captured output (MenuBuilderPreviewService::formatMarkup()) —
            // a service, never a record, and read-only by construction.
            'previewService' => $preview,
            'group' => $group,
            'tree' => $tree,
            'options' => $options,
            // Stage furniture: a brand label only, never a link.
            'siteName' => $site->name,
            'siteOptions' => $preview->siteOptions(),
            'userGroupOptions' => $preview->userGroupOptions(),
            'itemCount' => count($items),
            'enabledCount' => $enabledCount,
            'disabledCount' => count($items) - $enabledCount,
            // Split so the summary can say why the two numbers differ
            // without implying a dynamic item's synthesised children are
            // menu items the editor forgot about.
            'previewedItemCount' => MenuBuilderPreviewService::countPersistedNodes($nodes),
            'dynamicNodeCount' => MenuBuilderPreviewService::countDynamicNodes($nodes),
        ] + $this->currentUserAffordances());
    }
}

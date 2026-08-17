<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $currentUser = Craft::$app->getUser()->getIdentity();

        if (!$currentUser || (!$currentUser->admin && !$currentUser->can('menuBuilder:view'))) {
            throw new ForbiddenHttpException('You are not permitted to view navigation.');
        }

        return true;
    }

    public function actionIndex(string $groupHandle): Response
    {
        $groups = MenuBuilder::getInstance()->groups->getAll();
        $group = MenuBuilder::getInstance()->groups->getByHandle($groupHandle);

        if (!$group) {
            Craft::$app->getSession()->setError(Craft::t('menu-builder', 'That navigation menu doesn’t exist.'));

            return $this->redirect(UrlHelper::cpUrl('menu-builder'));
        }

        $search = Craft::$app->getRequest()->getQueryParam('search', '');
        $items = MenuBuilder::getInstance()->items->getTree($group->id);

        if ($search) {
            $items = $this->filterTree($items, mb_strtolower($search));
        }

        return $this->renderTemplate('menu-builder/dashboard/index', [
            'groups' => $groups,
            'group' => $group,
            'items' => $items,
            'search' => $search,
            'itemCount' => MenuBuilder::getInstance()->groups->countItems($group->id),
        ]);
    }

    /** Keeps a node if it or any descendant matches; expands its ancestors implicitly by inclusion. */
    private function filterTree(array $items, string $term): array
    {
        $result = [];

        foreach ($items as $item) {
            $children = $this->filterTree($item->children, $term);
            $matches = str_contains(mb_strtolower($item->title), $term);

            if ($matches || !empty($children)) {
                $item->children = $children;
                $result[] = $item;
            }
        }

        return $result;
    }
}

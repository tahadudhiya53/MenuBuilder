<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
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
        $tree = MenuBuilder::getInstance()->items->getTree($group->id);
        $items = $search ? $this->filterTree($tree, mb_strtolower($search)) : $tree;

        // Built from the unfiltered tree so a search never narrows the parents
        // the quick-add form can target.
        $parentOptions = array_merge(
            [['label' => Craft::t('menu-builder', 'Top level'), 'value' => '']],
            $this->parentOptions($tree, $group)
        );

        return $this->renderTemplate('menu-builder/dashboard/index', [
            'groups' => $groups,
            'group' => $group,
            'items' => $items,
            'search' => $search,
            'itemCount' => MenuBuilder::getInstance()->groups->countItems($group->id),
            'orphanedItemIds' => MenuBuilder::getInstance()->items->getOrphanedItemIds($group->id),
            'parentOptions' => $parentOptions,
        ]);
    }

    /**
     * Flattens the tree into indented <option>s for the quick-add parent picker.
     * Separators can't hold children, and anything whose children would land
     * past the group's maxDepth is left out — the same rules the service
     * enforces server-side on save.
     *
     * @param MenuBuilderItem[] $items
     * @return array<array{label: string, value: string}>
     */
    private function parentOptions(array $items, MenuBuilderGroup $group, int $level = 1): array
    {
        $options = [];

        foreach ($items as $item) {
            if ($item->type === MenuBuilderItem::TYPE_SEPARATOR) {
                continue;
            }

            if ($group->allowsDepth($level + 1)) {
                $options[] = [
                    'label' => str_repeat("\u{00a0}\u{00a0}\u{00a0}\u{00a0}", $level - 1)
                        . ($level > 1 ? "\u{21b3} " : '')
                        . ($item->title !== '' && $item->title !== null
                            ? $item->title
                            : Craft::t('menu-builder', '(untitled)')),
                    'value' => (string)$item->id,
                ];
            }

            $options = array_merge($options, $this->parentOptions($item->children, $group, $level + 1));
        }

        return $options;
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

<?php

namespace Tahadudhiya\MenuBuilder\controllers;

use Craft;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderLabelHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderLinkHealth;
use yii\base\Action;
use yii\web\Response;

class DashboardController extends BaseMenuBuilderController
{
    /**
     * The dashboard is read-only — every action on it renders the tree, so `view` covers all of
     * them.
    */
    public static function requiredPermissionForAction(string $actionId): string
    {
        return 'menuBuilder:view';
    }

    protected function requiredPermission(Action $action): string
    {
        return self::requiredPermissionForAction($action->id);
    }

    /** Both read-only screens say the same thing; see the base controller. */
    protected const PERMISSION_DENIED_MESSAGE = 'You are not permitted to view navigation.';

    public function actionIndex(string $groupHandle): Response
    {
        $group = $this->groupByHandleOrRedirect($groupHandle);

        if ($group instanceof Response) {
            return $group;
        }

        $groups = MenuBuilder::getInstance()->groups->getAll();
        $itemHealth = MenuBuilder::getInstance()->linkHealth->getForGroup($group->id);
        $search = trim((string)Craft::$app->getRequest()->getQueryParam('search', ''));
        $tree = MenuBuilder::getInstance()->items->getTree($group->id);
        $items = $search !== '' ? $this->filterTree($tree, mb_strtolower($search)) : $tree;

        // One label per row, resolved once. The linked element's title comes from the same
        // batched, per-site lookup the health pass above already performed, so naming every row
        // after the entry/category/asset it points at costs no extra query.
        $flat = MenuBuilder::getInstance()->items->getFlatForGroup($group->id);
        $itemLabels = MenuBuilderLabelHelper::itemLabels(
            $flat,
            MenuBuilder::getInstance()->linkHealth->getElementTitles($flat),
        );

        // Built from the unfiltered tree so a search never narrows the parents the quick-add form
        // can target.
        $parentOptions = array_merge(
            [['label' => Craft::t('menubuilder', 'Top level'), 'value' => '']],
            $this->parentOptions($tree, $group, $itemLabels)
        );

        return $this->renderTemplate('menubuilder/dashboard/index', [
            'groups' => $groups,
            'group' => $group,
            'items' => $items,
            'search' => $search,
            // The number of rows actually on screen, so an active search can say "N of M" instead
            // of leaving the header's total looking wrong.
            'visibleItemCount' => self::countTree($items),
            'itemCount' => MenuBuilder::getInstance()->groups->countItems($group->id),
            // Link health for every item in the menu, healthy ones included (see
            // MenuBuilderLinkHealthService) — the tree rows read it by item id, and the summary
            // counts only what needs attention.
            'itemHealth' => $itemHealth,
            'healthSummary' => MenuBuilderLinkHealth::summarize($itemHealth),
            'parentOptions' => $parentOptions,
            // Keyed by item ID; dashboard/_items.twig names every row from this.
            'itemLabels' => $itemLabels,
            // Edition facts, so the sidebar's create button knows whether there is a menu left to
            // create — see templates/dashboard/_sidebar-footer.twig.
            'edition' => MenuBuilder::getInstance()->menuLimit->cpSummary(),
        ] + $this->currentUserAffordances());
    }

    /**
     * Total rows in a (possibly filtered) tree, descendants included.
     *
     * @param MenuBuilderItem[] $items
    */
    public static function countTree(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            $count += 1 + self::countTree($item->children);
        }

        return $count;
    }

    /**
     * Flattens the tree into indented <option>s for the quick-add parent picker.
     *
     * @param MenuBuilderItem[] $items
     * @return array<array{label: string, value: string}>
    */
    private function parentOptions(array $items, MenuBuilderGroup $group, array $labels, int $level = 1): array
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
                        . ($labels[$item->id] ?? MenuBuilderLabelHelper::itemLabel($item)),
                    'value' => (string)$item->id,
                ];
            }

            $options = array_merge($options, $this->parentOptions($item->children, $group, $labels, $level + 1));
        }

        return $options;
    }

    /**
     * Keeps a node if it or any descendant matches; expands its ancestors implicitly by inclusion.
    */
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

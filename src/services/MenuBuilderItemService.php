<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\records\MenuBuilderItemRecord;
use Throwable;

/**
 * Owns menubuilder_items CRUD and hierarchy integrity. Trees are always built
 * from a single flat query per group (see getTree()) — never recursive
 * per-node queries — so rendering stays fast regardless of item count.
 */
class MenuBuilderItemService extends Component
{
    public function getById(int $id): ?MenuBuilderItem
    {
        $record = MenuBuilderItemRecord::findOne($id);

        return $record ? $this->recordToModel($record) : null;
    }

    /**
     * @return MenuBuilderItem[] Flat list, unordered relative to hierarchy.
     */
    public function getFlatForGroup(int $groupId, bool $includeDisabled = true): array
    {
        $query = MenuBuilderItemRecord::find()
            ->where(['groupId' => $groupId])
            ->orderBy(['parentId' => SORT_ASC, 'sortOrder' => SORT_ASC]);

        if (!$includeDisabled) {
            $query->andWhere(['enabled' => true]);
        }

        return array_map(fn(MenuBuilderItemRecord $record) => $this->recordToModel($record), $query->all());
    }

    /**
     * Assembles the full nested tree for a group from one flat query.
     *
     * @return MenuBuilderItem[] Top-level items, each with ->children populated recursively.
     */
    public function getTree(int $groupId, bool $includeDisabled = true): array
    {
        $flat = $this->getFlatForGroup($groupId, $includeDisabled);

        /** @var array<int,MenuBuilderItem> $byId */
        $byId = [];
        foreach ($flat as $item) {
            $item->children = [];
            $byId[$item->id] = $item;
        }

        $roots = [];
        foreach ($byId as $item) {
            if ($item->parentId !== null && isset($byId[$item->parentId])) {
                $byId[$item->parentId]->children[] = $item;
            } else {
                $roots[] = $item;
            }
        }

        $sort = function(array $items) use (&$sort) {
            usort($items, fn(MenuBuilderItem $a, MenuBuilderItem $b) => $a->sortOrder <=> $b->sortOrder);
            foreach ($items as $item) {
                $item->children = $sort($item->children);
            }

            return $items;
        };

        return $sort($roots);
    }

    public function save(MenuBuilderItem $item, bool $runValidation = true): bool
    {
        if ($runValidation && !$item->validate()) {
            return false;
        }

        if (!$this->validateHierarchy($item)) {
            return false;
        }

        $record = $item->id
            ? MenuBuilderItemRecord::findOne($item->id)
            : new MenuBuilderItemRecord();

        if (!$record) {
            $item->addError('id', Craft::t('menu-builder', 'Navigation item not found.'));

            return false;
        }

        $isNew = $record->id === null;

        $record->groupId = $item->groupId;
        $record->parentId = $item->parentId;
        $record->type = $item->type;
        $record->title = $item->title;
        $record->handle = $item->handle;
        $record->enabled = $item->enabled;
        $record->sortOrder = $isNew ? $this->nextSortOrder($item->groupId, $item->parentId) : $record->sortOrder;
        $record->clickable = $item->clickable;
        $record->elementId = $item->elementId;
        $record->customUrl = $item->customUrl;
        $record->target = $item->target;
        $record->rel = $item->rel;
        $record->cssClass = $item->cssClass;
        $record->htmlId = $item->htmlId;
        $record->htmlAttributes = Json::encode($item->htmlAttributes);
        $record->ariaLabel = $item->ariaLabel;
        $record->titleAttribute = $item->titleAttribute;
        $record->icon = $item->icon;
        $record->badge = $item->badge;
        $record->description = $item->description;
        $record->image = $item->image;
        $record->featured = $item->featured;
        $record->fallbackBehavior = $item->fallbackBehavior;
        $record->fallbackUrl = $item->fallbackUrl;
        $record->visibility = Json::encode($item->visibility);
        $record->metadata = Json::encode($item->metadata);

        if (!$record->save()) {
            $item->addErrors($record->getErrors());

            return false;
        }

        $item->id = $record->id;
        $item->uid = $record->uid;
        $this->invalidateGroup($item->groupId);

        return true;
    }

    /**
     * Reparents/reorders one item, re-validating depth/circularity/cross-group
     * server-side regardless of what the drag-and-drop UI already checked.
     */
    public function move(int $itemId, ?int $newParentId, int $newSortOrder): bool
    {
        $record = MenuBuilderItemRecord::findOne($itemId);

        if (!$record) {
            return false;
        }

        $item = $this->recordToModel($record);
        $item->parentId = $newParentId;

        if (!$this->validateHierarchy($item)) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $oldParentId = $record->parentId;
            $oldSortOrder = $record->sortOrder;

            $record->parentId = $newParentId;
            $record->sortOrder = $newSortOrder;

            if (!$record->save(false, ['parentId', 'sortOrder'])) {
                $transaction->rollBack();

                return false;
            }

            $this->closeSortOrderGap($item->groupId, $oldParentId, $oldSortOrder);
            $this->makeSortOrderRoom($item->groupId, $newParentId, $newSortOrder, $itemId);

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to move navigation item: ' . $exception->getMessage(), __METHOD__);

            return false;
        }

        $this->invalidateGroup($item->groupId);

        return true;
    }

    /**
     * Persists an explicit sibling order (e.g. after a same-parent drag or a
     * keyboard up/down move) without touching parentId.
     */
    public function reorderSiblings(int $groupId, ?int $parentId, array $itemIdsInOrder): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($itemIdsInOrder as $index => $itemId) {
                MenuBuilderItemRecord::updateAll(
                    ['sortOrder' => $index],
                    ['id' => (int)$itemId, 'groupId' => $groupId, 'parentId' => $parentId]
                );
            }
            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to reorder navigation items: ' . $exception->getMessage(), __METHOD__);

            return false;
        }

        $this->invalidateGroup($groupId);

        return true;
    }

    public function duplicate(int $itemId): ?MenuBuilderItem
    {
        $original = MenuBuilderItemRecord::findOne($itemId);

        if (!$original) {
            return null;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $clone = $this->duplicateRecord($original, $original->parentId);
            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to duplicate navigation item: ' . $exception->getMessage(), __METHOD__);

            return null;
        }

        $this->invalidateGroup((int)$original->groupId);

        return $this->recordToModel($clone);
    }

    /**
     * Clones every top-level item (and its descendants) from one group into
     * another, preserving hierarchy and titles. Used by
     * MenuBuilderGroupService::duplicate() — must run inside that method's
     * own transaction.
     */
    public function duplicateAllForGroup(int $sourceGroupId, int $targetGroupId): void
    {
        $roots = MenuBuilderItemRecord::find()
            ->where(['groupId' => $sourceGroupId, 'parentId' => null])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        foreach ($roots as $root) {
            $this->duplicateRecord($root, null, $targetGroupId, renameTitle: false);
        }
    }

    public function hasChildren(int $id): bool
    {
        return MenuBuilderItemRecord::find()->where(['parentId' => $id])->exists();
    }

    public function countDirectChildren(int $id): int
    {
        return (int)MenuBuilderItemRecord::find()->where(['parentId' => $id])->count();
    }

    public function countForGroup(int $groupId): int
    {
        return (int)MenuBuilderItemRecord::find()->where(['groupId' => $groupId])->count();
    }

    /**
     * Deletes an item. If it has children, `$keepChildren` decides their fate:
     * false (default) relies on the cascading FK on parentId to remove the
     * whole subtree; true reparents direct children up to the deleted item's
     * own parent (appended after its existing siblings there) before the
     * delete runs, so they survive.
     */
    public function deleteById(int $id, bool $keepChildren = false): bool
    {
        $record = MenuBuilderItemRecord::findOne($id);

        if (!$record) {
            return false;
        }

        $groupId = (int)$record->groupId;

        if (!$keepChildren) {
            $result = (bool)$record->delete();
            $this->invalidateGroup($groupId);

            return $result;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $newParentId = $record->parentId;
            $children = MenuBuilderItemRecord::find()->where(['parentId' => $id])->orderBy(['sortOrder' => SORT_ASC])->all();

            foreach ($children as $child) {
                $child->parentId = $newParentId;
                $child->sortOrder = $this->nextSortOrder($groupId, $newParentId);

                if (!$child->save(false, ['parentId', 'sortOrder'])) {
                    $transaction->rollBack();

                    return false;
                }
            }

            $result = (bool)$record->delete();
            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to delete navigation item: ' . $exception->getMessage(), __METHOD__);

            return false;
        }

        $this->invalidateGroup($groupId);

        return $result;
    }

    /**
     * Circular-reference, cross-group, and max-depth checks. Always runs
     * server-side — the CP's drag-and-drop client validation is UX-only.
     */
    private function validateHierarchy(MenuBuilderItem $item): bool
    {
        if ($item->parentId === null) {
            return true;
        }

        if ($item->parentId === $item->id) {
            $item->addError('parentId', Craft::t('menu-builder', 'An item cannot be its own parent.'));

            return false;
        }

        $parent = MenuBuilderItemRecord::findOne($item->parentId);

        if (!$parent) {
            $item->addError('parentId', Craft::t('menu-builder', 'The selected parent does not exist.'));

            return false;
        }

        if ((int)$parent->groupId !== $item->groupId) {
            $item->addError('parentId', Craft::t('menu-builder', 'A parent must belong to the same navigation group.'));

            return false;
        }

        $ancestorIds = [];
        $walk = $parent;
        while ($walk !== null) {
            if ($item->id !== null && (int)$walk->id === $item->id) {
                $item->addError('parentId', Craft::t('menu-builder', 'That move would create a circular reference.'));

                return false;
            }
            $ancestorIds[] = (int)$walk->id;
            $walk = $walk->parentId ? MenuBuilderItemRecord::findOne($walk->parentId) : null;
        }

        $group = MenuBuilder::getInstance()->groups->getById($item->groupId);

        if ($group !== null && $group->maxDepth !== null) {
            $parentLevel = count($ancestorIds); // 1-based level parent sits at
            $subtreeHeight = $item->id !== null ? $this->subtreeHeight($item->id) : 0;
            $deepestLevel = $parentLevel + 1 + $subtreeHeight;

            if (!$group->allowsDepth($deepestLevel)) {
                $item->addError('parentId', Craft::t('menu-builder', 'That move exceeds this group\'s maximum nesting depth.'));

                return false;
            }
        }

        return true;
    }

    /** Number of extra levels below $itemId (0 if it has no children). */
    private function subtreeHeight(int $itemId): int
    {
        $children = MenuBuilderItemRecord::find()->select(['id'])->where(['parentId' => $itemId])->column();

        if (empty($children)) {
            return 0;
        }

        $max = 0;
        foreach ($children as $childId) {
            $max = max($max, 1 + $this->subtreeHeight((int)$childId));
        }

        return $max;
    }

    private function duplicateRecord(MenuBuilderItemRecord $original, ?int $newParentId, ?int $newGroupId = null, bool $renameTitle = true): MenuBuilderItemRecord
    {
        $groupId = $newGroupId ?? (int)$original->groupId;

        $clone = new MenuBuilderItemRecord();
        $clone->groupId = $groupId;
        $clone->parentId = $newParentId;
        $clone->type = $original->type;
        $clone->title = $renameTitle ? $original->title . ' 2' : $original->title;
        $clone->handle = null;
        $clone->enabled = $original->enabled;
        $clone->sortOrder = $this->nextSortOrder($groupId, $newParentId);
        $clone->clickable = $original->clickable;
        $clone->elementId = $original->elementId;
        $clone->customUrl = $original->customUrl;
        $clone->target = $original->target;
        $clone->rel = $original->rel;
        $clone->cssClass = $original->cssClass;
        $clone->htmlId = null;
        $clone->htmlAttributes = $original->htmlAttributes;
        $clone->ariaLabel = $original->ariaLabel;
        $clone->titleAttribute = $original->titleAttribute;
        $clone->icon = $original->icon;
        $clone->badge = $original->badge;
        $clone->description = $original->description;
        $clone->image = $original->image;
        $clone->featured = $original->featured;
        $clone->fallbackBehavior = $original->fallbackBehavior;
        $clone->fallbackUrl = $original->fallbackUrl;
        $clone->visibility = $original->visibility;
        $clone->metadata = $original->metadata;
        $clone->save(false);

        foreach (MenuBuilderItemRecord::find()->where(['parentId' => $original->id])->all() as $child) {
            $this->duplicateRecord($child, $clone->id, $groupId, $renameTitle);
        }

        return $clone;
    }

    private function closeSortOrderGap(int $groupId, ?int $parentId, int $removedSortOrder): void
    {
        $query = MenuBuilderItemRecord::find()
            ->where(['groupId' => $groupId])
            ->andWhere(['>', 'sortOrder', $removedSortOrder]);
        $query->andWhere($parentId === null ? ['parentId' => null] : ['parentId' => $parentId]);

        foreach ($query->all() as $sibling) {
            $sibling->sortOrder -= 1;
            $sibling->save(false, ['sortOrder']);
        }
    }

    private function makeSortOrderRoom(int $groupId, ?int $parentId, int $sortOrder, int $excludeId): void
    {
        $query = MenuBuilderItemRecord::find()
            ->where(['groupId' => $groupId])
            ->andWhere(['>=', 'sortOrder', $sortOrder])
            ->andWhere(['not', ['id' => $excludeId]]);
        $query->andWhere($parentId === null ? ['parentId' => null] : ['parentId' => $parentId]);

        foreach ($query->all() as $sibling) {
            $sibling->sortOrder += 1;
            $sibling->save(false, ['sortOrder']);
        }
    }

    private function nextSortOrder(int $groupId, ?int $parentId): int
    {
        $query = MenuBuilderItemRecord::find()->where(['groupId' => $groupId]);
        $query->andWhere($parentId === null ? ['parentId' => null] : ['parentId' => $parentId]);
        $max = $query->max('sortOrder');

        return $max === null ? 0 : ((int)$max + 1);
    }

    private function invalidateGroup(int $groupId): void
    {
        $group = MenuBuilder::getInstance()->groups->getById($groupId);

        if ($group !== null) {
            MenuBuilder::getInstance()->cache->invalidateGroup($group->handle);
        }
    }

    private function recordToModel(MenuBuilderItemRecord $record): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->id = $record->id;
        $item->groupId = $record->groupId;
        $item->parentId = $record->parentId;
        $item->type = $record->type;
        $item->title = $record->title;
        $item->handle = $record->handle;
        $item->enabled = (bool)$record->enabled;
        $item->sortOrder = (int)$record->sortOrder;
        $item->clickable = (bool)$record->clickable;
        $item->elementId = $record->elementId;
        $item->customUrl = $record->customUrl;
        $item->target = $record->target;
        $item->rel = $record->rel;
        $item->cssClass = $record->cssClass;
        $item->htmlId = $record->htmlId;
        $item->htmlAttributes = $this->decodeArray($record->htmlAttributes);
        $item->ariaLabel = $record->ariaLabel;
        $item->titleAttribute = $record->titleAttribute;
        $item->icon = $record->icon;
        $item->badge = $record->badge;
        $item->description = $record->description;
        $item->image = $record->image;
        $item->featured = (bool)$record->featured;
        $item->fallbackBehavior = $record->fallbackBehavior;
        $item->fallbackUrl = $record->fallbackUrl;
        $item->visibility = $this->decodeArray($record->visibility);
        $item->metadata = $this->decodeArray($record->metadata);
        $item->uid = $record->uid;
        $item->dateCreated = $record->dateCreated;
        $item->dateUpdated = $record->dateUpdated;

        return $item;
    }

    private function decodeArray(?string $json): array
    {
        if (!$json) {
            return [];
        }

        try {
            $decoded = Json::decode($json);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}

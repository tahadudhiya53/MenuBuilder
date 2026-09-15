<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderHierarchyHelper;
use Tahadudhiya\MenuBuilder\helpers\TextHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\records\MenuBuilderGroupRecord;
use Tahadudhiya\MenuBuilder\records\MenuBuilderItemRecord;
use Throwable;
use yii\base\Exception;
use yii\db\Expression;

/**
 * Owns menubuilder_items CRUD and hierarchy integrity.
*/
class MenuBuilderItemService extends Component
{
    /**
     * Why the last {@see move()} was refused — read back by {@see getLastMoveError()}.
    */
    private ?string $moveError = null;

    public function getById(int $id): ?MenuBuilderItem
    {
        $record = MenuBuilderItemRecord::findOne($id);

        return $record ? $this->recordToModel($record) : null;
    }

    /** @return MenuBuilderItem[] Flat list, unordered relative to hierarchy. */
    public function getFlatForGroup(int $groupId, bool $includeDisabled = true): array
    {
        $query = MenuBuilderItemRecord::find()
            ->where(['groupId' => $groupId])
            ->orderBy(['parentId' => SORT_ASC, 'sortOrder' => SORT_ASC, 'id' => SORT_ASC]);

        if (!$includeDisabled) {
            $query->andWhere(['enabled' => true]);
        }

        /** @var MenuBuilderItemRecord[] $records */
        $records = $query->all();

        return array_map(fn(MenuBuilderItemRecord $record) => $this->recordToModel($record), $records);
    }

    /**
     * The visibility rules of a group's items, keyed by item ID.
     *
     * @return array<int,array> Visibility rule bags, keyed by item ID.
    */
    public function getVisibilityRulesForGroup(int $groupId, bool $includeDisabled = true): array
    {
        // No ORDER BY: the result is a lookup map, not a sequence.
        $query = MenuBuilderItemRecord::find()
            ->select(['id', 'visibility'])
            ->where(['groupId' => $groupId])
            ->asArray();

        if (!$includeDisabled) {
            $query->andWhere(['enabled' => true]);
        }

        $rules = [];

        foreach ($query->all() as $row) {
            $rules[(int)$row['id']] = ConfigHelper::decodeJsonBag($row['visibility']);
        }

        return $rules;
    }

    /**
     * Assembles the full nested tree for a group from one flat query.
     *
     * @return MenuBuilderItem[] Top-level items, each with ->children populated recursively.
    */
    public function getTree(int $groupId, bool $includeDisabled = true): array
    {
        $flat = $this->getFlatForGroup($groupId, $includeDisabled);

        if (!$includeDisabled) {
            $reachable = MenuBuilderHierarchyHelper::idsReachableFromRoots(
                array_map(
                    fn(MenuBuilderItem $item) => ['id' => (int)$item->id, 'parentId' => $item->parentId, 'sortOrder' => $item->sortOrder],
                    $flat
                )
            );

            $flat = array_values(array_filter($flat, fn(MenuBuilderItem $item) => isset($reachable[$item->id])));
        }

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
            // `id` breaks a sortOrder tie, so rows left sharing a number by legacy data or a
            // half-applied concurrent write still render in the same order on every request.
            usort($items, fn(MenuBuilderItem $a, MenuBuilderItem $b) => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]);
            foreach ($items as $item) {
                $item->children = $sort($item->children);
            }

            return $items;
        };

        return $sort($roots);
    }

    public function save(MenuBuilderItem $item, bool $runValidation = true): bool
    {
        // Custom field content is a Craft element beside the item row, so it validates through
        // Craft rather than through defineRules().
        if ($runValidation && !$item->validate()) {
            return false;
        }

        if ($runValidation && !MenuBuilder::getInstance()->itemContent->validateContent($item)) {
            return false;
        }

        $record = $item->id
            ? MenuBuilderItemRecord::findOne($item->id)
            : new MenuBuilderItemRecord();

        if (!$record) {
            $item->addError('id', Craft::t('menubuilder', 'Navigation item not found.'));

            return false;
        }

        if ($record->id !== null && !self::isGroupChangeAllowed((int)$record->groupId, $item->groupId)) {
            $item->addError('groupId', Craft::t('menubuilder', 'A navigation menu item cannot be moved to a different navigation group.'));

            return false;
        }

        $isNew = $record->id === null;

        // A reparent through the edit form lands the item in a sibling set it never had a position
        // in, so its old sortOrder is meaningless there — keeping it would collide with whichever
        // existing sibling already holds that number, leaving the tie broken by whatever order the
        // database happened to return.
        $isReparent = !$isNew
            && ($record->parentId === null ? null : (int)$record->parentId) !== $item->parentId;

        // A save that changes where the item sits is a hierarchy mutation, exactly like a drag, and
        // has to serialise against one: the edit form's parent picker can create a cycle with a
        // concurrent drag if each validates against the other's pre-commit state.
        $needsLock = $isNew || $isReparent;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if ($needsLock) {
                $this->lockGroup($item->groupId);
            }

            if (!MenuBuilder::getInstance()->itemContent->save($item)) {
                $transaction->rollBack();

                return false;
            }

            if (!$this->saveRecord($record, $item, $isNew, $isReparent)) {
                $transaction->rollBack();

                return false;
            }

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to save navigation item: ' . $exception->getMessage(), __METHOD__);

            return false;
        }

        $item->id = $record->id;
        $item->uid = $record->uid;
        $this->invalidateGroup($item->groupId);

        return true;
    }

    /**
     * The write half of {@see save()}: the checks that must see the state they're writing against,
     * then the column assignments and the row itself.
    */
    private function saveRecord(MenuBuilderItemRecord $record, MenuBuilderItem $item, bool $isNew, bool $isReparent): bool
    {
        // A new item is the only case where `groupId` is taken from the request, so it's the only
        // case where it can name a group that isn't there (deleted in another tab, an imported or
        // tampered payload) — an existing item can't change groups at all, and the FK cascade
        // means its group is still there by definition.
        if ($isNew && !$this->groupExists($item->groupId)) {
            $item->addError('groupId', Craft::t('menubuilder', 'The selected navigation group does not exist.'));

            return false;
        }

        if (!$this->validateHierarchy($item)) {
            return false;
        }

        $record->groupId = $item->groupId;
        $record->parentId = $item->parentId;
        $record->type = $item->type;
        $record->title = $item->title;
        $record->handle = $item->handle;
        $record->enabled = $item->enabled;
        $record->sortOrder = ($isNew || $isReparent)
            ? $this->nextSortOrder($item->groupId, $item->parentId)
            : $record->sortOrder;
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
        $record->contentId = $item->contentId;

        if (!$record->save()) {
            $item->addErrors($record->getErrors());

            return false;
        }

        return true;
    }

    /**
     * Reparents and/or repositions one item — the single write path behind every drag/drop,
     * keyboard move and reorder in the CP.
     *
     * @param int[] $requestedSiblingIds Desired order of the destination sibling set, including $itemId.
    */
    public function move(int $itemId, ?int $newParentId, int $newSortOrder, array $requestedSiblingIds = []): bool
    {
        $this->moveError = null;
        $record = MenuBuilderItemRecord::findOne($itemId);

        if (!$record) {
            $this->moveError = Craft::t('menubuilder', 'Navigation menu not found.');

            return false;
        }

        $groupId = (int)$record->groupId;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $this->lockGroup($groupId);

            // Re-read under the lock: between findOne() above and the lock being granted, another
            // request may have deleted this row or moved it somewhere that changes what's legal
            // here.
            $record = MenuBuilderItemRecord::findOne($itemId);

            if (!$record || (int)$record->groupId !== $groupId) {
                $transaction->rollBack();
                $this->moveError = Craft::t('menubuilder', 'Navigation menu not found.');

                return false;
            }

            $item = $this->recordToModel($record);
            $item->parentId = $newParentId;

            if (!$this->validateHierarchy($item)) {
                $transaction->rollBack();
                $this->moveError = $item->getFirstError('parentId')
                    ?? Craft::t('menubuilder', 'That move isn’t allowed.');

                return false;
            }

            // One snapshot, one plan: what the new parent is and which sortOrders have to change in
            // the destination set and in the set being left.
            $plan = MenuBuilderHierarchyHelper::planMove(
                $this->snapshotForGroup($groupId),
                $itemId,
                $newParentId,
                $newSortOrder,
                $requestedSiblingIds
            );

            $record->parentId = $plan['parentId'];

            if (!$record->save(false, ['parentId'])) {
                $transaction->rollBack();
                $this->moveError = Craft::t('menubuilder', 'That move isn’t allowed.');

                return false;
            }

            $this->applySortOrders($plan['sortOrders']);

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to move navigation item: ' . $exception->getMessage(), __METHOD__);
            $this->moveError = Craft::t('menubuilder', 'That move isn’t allowed.');

            return false;
        }

        $this->invalidateGroup($groupId);

        return true;
    }

    /**
     * Why the last {@see move()} was refused, so the CP can say which rule was broken instead of a
     * generic failure.
    */
    public function getLastMoveError(): ?string
    {
        return $this->moveError;
    }

    /**
     * Persists an explicit sibling order (e.g.
    */
    public function reorderSiblings(int $groupId, ?int $parentId, array $itemIdsInOrder): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $this->lockGroup($groupId);

            $rows = $this->snapshotForGroup($groupId);
            $childMap = MenuBuilderHierarchyHelper::childMap($rows);
            $ordered = MenuBuilderHierarchyHelper::resolveSiblingOrder(
                MenuBuilderHierarchyHelper::siblingIds($childMap, $parentId),
                $itemIdsInOrder
            );

            $this->applySortOrders(
                MenuBuilderHierarchyHelper::sortOrderAssignments($ordered, $this->sortOrderMap($rows))
            );

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
     * Clones every top-level item (and its descendants) from one group into another, preserving
     * hierarchy and titles.
    */
    public function duplicateAllForGroup(int $sourceGroupId, int $targetGroupId): void
    {
        /** @var MenuBuilderItemRecord[] $roots */
        $roots = MenuBuilderItemRecord::find()
            ->where(['groupId' => $sourceGroupId, 'parentId' => null])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        foreach ($roots as $root) {
            $this->duplicateRecord($root, null, $targetGroupId, renameTitle: false);
        }
    }

    /**
     * IDs of items in the group whose linked element (entry/category/asset) no longer exists.
     *
     * @return array<int,true> Item IDs, as a set for O(1) lookup in Twig.
    */
    public function getOrphanedItemIds(int $groupId): array
    {
        return MenuBuilder::getInstance()->linkHealth->getMissingElementItemIds($groupId);
    }

    /**
     * Groups containing at least one enabled `dynamic` item — a single indexed query on `type`.
     *
     * @return int[] Distinct group IDs.
    */
    public function getGroupIdsWithDynamicItems(): array
    {
        return MenuBuilderItemRecord::distinctGroupIds([
            'type' => MenuBuilderItem::TYPE_DYNAMIC,
            'enabled' => true,
        ]);
    }

    /**
     * Every enabled `dynamic` item's stored source config, grouped by group ID — one query on the
     * same indexed `type` column {@see getGroupIdsWithDynamicItems()} uses, with `metadata`
     * selected so the caller can decide *which* dynamic sources a changed element could belong to,
     * instead of invalidating every group that has any dynamic item.
     *
     * @return array<int,array<int,array<string,mixed>>> groupId => list of `dynamicSource` configs.
    */
    public function getDynamicSourceConfigsByGroup(): array
    {
        $rows = MenuBuilderItemRecord::find()
            ->select(['groupId', 'metadata'])
            ->where(['type' => MenuBuilderItem::TYPE_DYNAMIC, 'enabled' => true])
            ->asArray()
            ->all();

        $configs = [];

        foreach ($rows as $row) {
            $metadata = ConfigHelper::decodeJsonBag($row['metadata'] ?? null);
            $source = $metadata['dynamicSource'] ?? null;

            if (is_array($source)) {
                $configs[(int)$row['groupId']][] = $source;
            }
        }

        return $configs;
    }

    /**
     * Bulk actions.
     *
     * @param int[] $ids
    */
    public function bulkSetEnabled(array $ids, bool $enabled): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($ids as $id) {
                $item = $this->getById((int)$id);

                if ($item === null) {
                    continue;
                }

                $item->enabled = $enabled;

                if (!$this->save($item, runValidation: false)) {
                    $transaction->rollBack();

                    return false;
                }
            }

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to bulk-update navigation items: ' . $exception->getMessage(), __METHOD__);

            return false;
        }

        return true;
    }

    /** @param int[] $ids */
    public function bulkDelete(array $ids): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($ids as $id) {
                // deleteById() already invalidates the owning group's cache and relies on the
                // cascading FK for descendants — a bulk delete is just that, repeated inside one
                // transaction.
                $this->deleteById((int)$id, keepChildren: false);
            }

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to bulk-delete navigation items: ' . $exception->getMessage(), __METHOD__);

            return false;
        }

        return true;
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
     * Deletes an item.
    */
    public function deleteById(int $id, bool $keepChildren = false): bool
    {
        $record = MenuBuilderItemRecord::findOne($id);

        if (!$record) {
            return false;
        }

        $groupId = (int)$record->groupId;

        if (!$keepChildren) {
            // The subtree's content elements first: the parentId cascade below removes those item
            // rows inside the database, so once it has run there is nothing left naming their
            // content.
            MenuBuilder::getInstance()->itemContent->deleteByIds($this->contentIdsInSubtree($id));

            $result = (bool)$record->delete();
            $this->invalidateGroup($groupId);

            return $result;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $this->lockGroup($groupId);

            $newParentId = $record->parentId;
            /** @var MenuBuilderItemRecord[] $children */
            $children = MenuBuilderItemRecord::find()->where(['parentId' => $id])->orderBy(['sortOrder' => SORT_ASC])->all();

            foreach ($children as $child) {
                $child->parentId = $newParentId;
                $child->sortOrder = $this->nextSortOrder($groupId, $newParentId);

                if (!$child->save(false, ['parentId', 'sortOrder'])) {
                    $transaction->rollBack();

                    return false;
                }
            }

            // Only this item's own content: its children have been reparented above, so they and
            // their content survive.
            MenuBuilder::getInstance()->itemContent->deleteByIds([
                $record->contentId !== null ? (int)$record->contentId : null,
            ]);

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
     * An item's groupId is fixed at creation.
    */
    public static function isGroupChangeAllowed(?int $original, int $requested): bool
    {
        return $original === null || $original === $requested;
    }

    /**
     * Circular-reference, cross-group, and max-depth checks.
    */
    private function validateHierarchy(MenuBuilderItem $item): bool
    {
        if ($item->parentId !== null) {
            if ($item->parentId === $item->id) {
                $item->addError('parentId', Craft::t('menubuilder', 'An item cannot be its own parent.'));

                return false;
            }

            $parent = MenuBuilderItemRecord::findOne($item->parentId);

            if (!$parent) {
                $item->addError('parentId', Craft::t('menubuilder', 'The selected parent does not exist.'));

                return false;
            }

            if ((int)$parent->groupId !== $item->groupId) {
                $item->addError('parentId', Craft::t('menubuilder', 'A parent must belong to the same navigation group.'));

                return false;
            }
        }

        $rows = $this->snapshotForGroup($item->groupId);
        $parentMap = MenuBuilderHierarchyHelper::parentMap($rows);
        $childMap = MenuBuilderHierarchyHelper::childMap($rows);

        if ($item->parentId !== null) {
            // A cycle already present in the stored rows — two moves that each validated against
            // the other's pre-commit state, or a row edited straight in the database — makes
            // every depth answer below meaningless, so fail closed rather than nest anything into
            // it.
            if (MenuBuilderHierarchyHelper::ancestryIsCyclic($parentMap, $item->parentId)) {
                $item->addError('parentId', Craft::t('menubuilder', 'That move would create a circular reference.'));

                return false;
            }

            if ($item->id !== null && MenuBuilderHierarchyHelper::wouldCreateCycle($parentMap, $item->id, $item->parentId)) {
                $item->addError('parentId', Craft::t('menubuilder', 'That move would create a circular reference.'));

                return false;
            }
        }

        $group = MenuBuilder::getInstance()->groups->getById($item->groupId);

        if ($group !== null && $group->maxDepth !== null) {
            // Measured against the subtree's deepest row, not the item's own level, because the
            // descendants travel with it.
            $deepestLevel = MenuBuilderHierarchyHelper::deepestLevelAfterMove(
                $parentMap,
                $childMap,
                $item->id,
                $item->parentId
            );

            if (!$group->allowsDepth($deepestLevel)) {
                $item->addError('parentId', Craft::t('menubuilder', 'That move exceeds this group\'s maximum nesting depth.'));

                return false;
            }
        }

        return true;
    }

    /**
     * One group's hierarchy columns — everything {@see MenuBuilderHierarchyHelper} needs and
     * nothing else.
     *
     * @return array<int,array{id:int,parentId:int|null,sortOrder:int}>
    */
    /**
     * The item that owns the given content element, or null when it has been stranded.
    */
    public function getByContentId(int $contentId): ?MenuBuilderItem
    {
        $record = MenuBuilderItemRecord::findOne(['contentId' => $contentId]);

        return $record ? $this->recordToModel($record) : null;
    }

    /**
     * Deletes every content element belonging to a menu, without touching the items themselves.
    */
    public function deleteContentForGroup(int $groupId): void
    {
        $contentIds = MenuBuilderItemRecord::find()
            ->select(['contentId'])
            ->where(['groupId' => $groupId])
            ->andWhere(['not', ['contentId' => null]])
            ->column();

        if ($contentIds !== []) {
            MenuBuilder::getInstance()->itemContent->deleteByIds(array_map('intval', $contentIds));
        }
    }

    /**
     * The content element IDs of an item and everything under it — what a cascading subtree
     * delete is about to strand.
     *
     * @return int[]
    */
    private function contentIdsInSubtree(int $itemId): array
    {
        $record = MenuBuilderItemRecord::findOne($itemId);

        if (!$record) {
            return [];
        }

        $rows = MenuBuilderItemRecord::find()
            ->select(['id', 'parentId', 'sortOrder', 'contentId'])
            ->where(['groupId' => $record->groupId])
            ->asArray()
            ->all();

        $contentIds = [];
        // `sortOrder` is selected because childMap() orders by it — the order is irrelevant to a
        // delete, but a projection missing a column the helper reads is a fatal, not a wrong
        // answer.
        $childMap = MenuBuilderHierarchyHelper::childMap(array_map(fn(array $row) => [
            'id' => (int)$row['id'],
            'parentId' => $row['parentId'] === null ? null : (int)$row['parentId'],
            'sortOrder' => (int)$row['sortOrder'],
        ], $rows));

        $wanted = array_flip([$itemId, ...MenuBuilderHierarchyHelper::descendantIds($childMap, $itemId)]);

        foreach ($rows as $row) {
            if ($row['contentId'] !== null && isset($wanted[(int)$row['id']])) {
                $contentIds[] = (int)$row['contentId'];
            }
        }

        return $contentIds;
    }

    /**
     * A menu's field layout ID, or null when it defines no fields — what a duplicated item's new
     * content element is built on.
    */
    private function fieldLayoutIdForGroup(int $groupId): ?int
    {
        $group = MenuBuilder::getInstance()->groups->getById($groupId);

        return $group !== null && $group->hasCustomFields() ? $group->fieldLayoutId : null;
    }

    private function snapshotForGroup(int $groupId): array
    {
        $rows = MenuBuilderItemRecord::find()
            ->select(['id', 'parentId', 'sortOrder'])
            ->where(['groupId' => $groupId])
            ->asArray()
            ->all();

        return array_map(fn(array $row) => [
            'id' => (int)$row['id'],
            'parentId' => $row['parentId'] === null ? null : (int)$row['parentId'],
            'sortOrder' => (int)$row['sortOrder'],
        ], $rows);
    }

    /**
     * @param array<int,array{id:int,parentId:int|null,sortOrder:int}> $rows
     * @return array<int,int> id => sortOrder
    */
    private function sortOrderMap(array $rows): array
    {
        return array_column($rows, 'sortOrder', 'id');
    }

    /**
     * Serialises every mutation of one group's hierarchy behind a row lock on the group itself.
    */
    private function lockGroup(int $groupId): void
    {
        $db = Craft::$app->getDb();

        if ($db->getTransaction() === null || !($db->getIsMysql() || $db->getIsPgsql())) {
            return;
        }

        $db->createCommand(
            'SELECT [[id]] FROM ' . MenuBuilderGroupRecord::tableName() . ' WHERE [[id]] = :groupId FOR UPDATE',
            [':groupId' => $groupId]
        )->queryScalar();
    }

    /**
     * Writes a batch of `sortOrder` values as one statement per chunk (`CASE id WHEN … THEN …`)
     * instead of one UPDATE per row, so repositioning inside a 500-item sibling set costs a couple
     * of queries rather than 500 round trips inside a held lock.
     *
     * @param array<int,int> $assignments id => new sortOrder
    */
    private function applySortOrders(array $assignments): void
    {
        if (empty($assignments)) {
            return;
        }

        $db = Craft::$app->getDb();

        foreach (array_chunk($assignments, 200, true) as $chunk) {
            $case = 'CASE [[id]]';
            $params = [];
            $index = 0;

            foreach ($chunk as $id => $sortOrder) {
                $case .= " WHEN :mbId{$index} THEN :mbSort{$index}";
                $params[":mbId{$index}"] = (int)$id;
                $params[":mbSort{$index}"] = (int)$sortOrder;
                $index++;
            }

            $case .= ' END';

            $db->createCommand()
                ->update(
                    MenuBuilderItemRecord::tableName(),
                    ['sortOrder' => new Expression($case, $params)],
                    ['id' => array_keys($chunk)]
                )
                ->execute();
        }
    }

    /**
     * Authoritative (uncached) existence check for the owning group — a group deleted after this
     * request's group cache was warmed must not still look present.
    */
    private function groupExists(?int $groupId): bool
    {
        if ($groupId === null) {
            return false;
        }

        return MenuBuilderGroupRecord::find()->where(['id' => $groupId])->exists();
    }

    private function duplicateRecord(MenuBuilderItemRecord $original, ?int $newParentId, ?int $newGroupId = null, bool $renameTitle = true): MenuBuilderItemRecord
    {
        $groupId = $newGroupId ?? (int)$original->groupId;

        $clone = new MenuBuilderItemRecord();
        $clone->groupId = $groupId;
        $clone->parentId = $newParentId;
        $clone->type = $original->type;
        // Truncated, not just suffixed: `title` fills a varchar(255), so appending to one that
        // already fills it produced a row the database rejected — and because this runs inside a
        // transaction that then throws, the editor was told the duplicate "couldn't be done"
        // rather than getting a copy with a shortened name. Same answer, and the same helper, as
        // the menu-name side of MenuBuilderGroupService::duplicate().
        $clone->title = $renameTitle
            ? TextHelper::truncate($original->title . ' 2', MenuBuilderItem::MAX_TITLE_LENGTH)
            : $original->title;
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
        // A *copy* of the content element, never a shared id: two items pointing at one would mean
        // editing either one's fields silently rewrote the other's, and deleting either would empty
        // both.
        $clone->contentId = MenuBuilder::getInstance()->itemContent->duplicateContent(
            $original->contentId !== null ? (int)$original->contentId : null,
            $this->fieldLayoutIdForGroup($groupId),
        );

        // Must throw rather than return a half-made clone: this runs inside a transaction, and a
        // failed save leaves $clone->id null — every descendant below would then be written with
        // parentId = null, committing the copied subtree as a pile of orphaned root items.
        if (!$clone->save(false)) {
            throw new Exception('Couldn’t duplicate navigation item ' . $original->id . '.');
        }

        // Ordered, so the copy's sibling order matches the original's — nextSortOrder() numbers
        // each child as it's written, so an unordered query hands the clone whatever order the
        // database felt like returning.
        /** @var MenuBuilderItemRecord[] $children */
        $children = MenuBuilderItemRecord::find()
            ->where(['parentId' => $original->id])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        foreach ($children as $child) {
            $this->duplicateRecord($child, $clone->id, $groupId, $renameTitle);
        }

        return $clone;
    }

    private function nextSortOrder(int $groupId, ?int $parentId): int
    {
        $query = MenuBuilderItemRecord::find()->where(['groupId' => $groupId]);
        $query->andWhere($parentId === null ? ['parentId' => null] : ['parentId' => $parentId]);
        $max = $query->max('sortOrder');

        return $max === null ? 0 : ((int)$max + 1);
    }

    /**
     * One menu's cached tree only — never a blanket flush, and never another menu's.
    */
    private function invalidateGroup(int $groupId): void
    {
        MenuBuilder::getInstance()->cache->invalidateGroupId($groupId);
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
        $item->htmlAttributes = ConfigHelper::decodeJsonBag($record->htmlAttributes);
        $item->ariaLabel = $record->ariaLabel;
        $item->titleAttribute = $record->titleAttribute;
        $item->icon = $record->icon;
        $item->badge = $record->badge;
        $item->description = $record->description;
        $item->image = $record->image;
        $item->featured = (bool)$record->featured;
        $item->fallbackBehavior = $record->fallbackBehavior;
        $item->fallbackUrl = $record->fallbackUrl;
        $item->visibility = ConfigHelper::decodeJsonBag($record->visibility);
        $item->metadata = ConfigHelper::decodeJsonBag($record->metadata);
        $item->contentId = $record->contentId !== null ? (int)$record->contentId : null;
        $item->uid = $record->uid;
        $item->dateCreated = $record->dateCreated;
        $item->dateUpdated = $record->dateUpdated;

        return $item;
    }
}

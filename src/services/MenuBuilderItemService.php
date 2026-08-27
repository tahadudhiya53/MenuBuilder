<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Json;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderHierarchyHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\records\MenuBuilderGroupRecord;
use Tahadudhiya\MenuBuilder\records\MenuBuilderItemRecord;
use Throwable;
use yii\base\Exception;
use yii\db\Expression;

/**
 * Owns menubuilder_items CRUD and hierarchy integrity. Trees are always built
 * from a single flat query per group (see getTree()) — never recursive
 * per-node queries — so rendering stays fast regardless of item count.
 */
class MenuBuilderItemService extends Component
{
    /** Why the last {@see move()} was refused — read back by {@see getLastMoveError()}. */
    private ?string $moveError = null;

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
            ->orderBy(['parentId' => SORT_ASC, 'sortOrder' => SORT_ASC, 'id' => SORT_ASC]);

        if (!$includeDisabled) {
            $query->andWhere(['enabled' => true]);
        }

        /** @var MenuBuilderItemRecord[] $records */
        $records = $query->all();

        return array_map(fn(MenuBuilderItemRecord $record) => $this->recordToModel($record), $records);
    }

    /**
     * Assembles the full nested tree for a group from one flat query.
     *
     * With disabled items excluded, a disabled parent's descendants are
     * excluded with it: they have no parent row left to nest under, and
     * nesting-by-presence alone would promote them to the top level of the
     * rendered menu — see
     * {@see MenuBuilderHierarchyHelper::idsReachableFromRoots()}. The CP tree
     * (`includeDisabled: true`) sees every row and is unaffected.
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
            // `id` breaks a sortOrder tie, so rows left sharing a number by
            // legacy data or a half-applied concurrent write still render in
            // the same order on every request.
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
        if ($runValidation && !$item->validate()) {
            return false;
        }

        $record = $item->id
            ? MenuBuilderItemRecord::findOne($item->id)
            : new MenuBuilderItemRecord();

        if (!$record) {
            $item->addError('id', Craft::t('menu-builder', 'Navigation item not found.'));

            return false;
        }

        if ($record->id !== null && !self::isGroupChangeAllowed((int)$record->groupId, $item->groupId)) {
            $item->addError('groupId', Craft::t('menu-builder', 'A navigation menu item cannot be moved to a different navigation group.'));

            return false;
        }

        $isNew = $record->id === null;

        // A reparent through the edit form lands the item in a sibling set
        // it never had a position in, so its old sortOrder is meaningless
        // there — keeping it would collide with whichever existing sibling
        // already holds that number, leaving the tie broken by whatever
        // order the database happened to return. Append instead.
        $isReparent = !$isNew
            && ($record->parentId === null ? null : (int)$record->parentId) !== $item->parentId;

        // A save that changes where the item sits is a hierarchy mutation,
        // exactly like a drag, and has to serialise against one: the edit
        // form's parent picker can create a cycle with a concurrent drag if
        // each validates against the other's pre-commit state. Field-only
        // edits (a title, a URL, an enabled toggle) touch no structure and
        // stay lock-free.
        $needsLock = $isNew || $isReparent;
        $transaction = $needsLock ? Craft::$app->getDb()->beginTransaction() : null;

        try {
            if ($needsLock) {
                $this->lockGroup($item->groupId);
            }

            if (!$this->saveRecord($record, $item, $isNew, $isReparent)) {
                $transaction?->rollBack();

                return false;
            }

            $transaction?->commit();
        } catch (Throwable $exception) {
            $transaction?->rollBack();
            Craft::warning('Failed to save navigation item: ' . $exception->getMessage(), __METHOD__);

            return false;
        }

        $item->id = $record->id;
        $item->uid = $record->uid;
        $this->invalidateGroup($item->groupId);

        return true;
    }

    /**
     * The write half of {@see save()}: the checks that must see the state
     * they're writing against, then the column assignments and the row
     * itself. Split out so a structural save can run the whole thing inside
     * one locked transaction without a field-only save paying for one.
     */
    private function saveRecord(MenuBuilderItemRecord $record, MenuBuilderItem $item, bool $isNew, bool $isReparent): bool
    {
        // A new item is the only case where `groupId` is taken from the
        // request, so it's the only case where it can name a group that
        // isn't there (deleted in another tab, an imported or tampered
        // payload) — an existing item can't change groups at all, and the
        // FK cascade means its group is still there by definition. Without
        // this the FK is the only thing standing between a stale groupId
        // and a database integrity exception surfacing as a 500; an
        // unsaveable item should fail as a field error, like every other
        // invalid input.
        if ($isNew && !$this->groupExists($item->groupId)) {
            $item->addError('groupId', Craft::t('menu-builder', 'The selected navigation group does not exist.'));

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

        if (!$record->save()) {
            $item->addErrors($record->getErrors());

            return false;
        }

        return true;
    }

    /**
     * Reparents and/or repositions one item — the single write path behind
     * every drag/drop, keyboard move and reorder in the CP.
     *
     * The whole operation is one transaction that starts by taking a row
     * lock on the owning group, so concurrent moves inside the same menu are
     * serialised rather than each validating against the other's pre-commit
     * state (two such moves are exactly how a cycle gets past a check that
     * ran before the transaction). Everything is then re-read inside that
     * lock and re-validated server-side — depth, circularity and cross-group
     * — regardless of what the drag-and-drop UI already checked.
     *
     * `$requestedSiblingIds` is the client's view of the destination set's
     * order. It is a preference, not the truth: it's reconciled against the
     * set's real membership (see
     * {@see MenuBuilderHierarchyHelper::resolveSiblingOrder()}) so a stale
     * screen can't resurrect a deleted row, drop a row it never saw, or pull
     * in a row from another parent.
     *
     * Descendants are carried along untouched — they reference their parent
     * by id, so moving the item moves the whole subtree by construction, and
     * the depth check measures the subtree's deepest row rather than the
     * item's own.
     *
     * @param int[] $requestedSiblingIds Desired order of the destination sibling set, including $itemId.
     */
    public function move(int $itemId, ?int $newParentId, int $newSortOrder, array $requestedSiblingIds = []): bool
    {
        $this->moveError = null;
        $record = MenuBuilderItemRecord::findOne($itemId);

        if (!$record) {
            $this->moveError = Craft::t('menu-builder', 'Navigation menu not found.');

            return false;
        }

        $groupId = (int)$record->groupId;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $this->lockGroup($groupId);

            // Re-read under the lock: between findOne() above and the lock
            // being granted, another request may have deleted this row or
            // moved it somewhere that changes what's legal here.
            $record = MenuBuilderItemRecord::findOne($itemId);

            if (!$record || (int)$record->groupId !== $groupId) {
                $transaction->rollBack();
                $this->moveError = Craft::t('menu-builder', 'Navigation menu not found.');

                return false;
            }

            $item = $this->recordToModel($record);
            $item->parentId = $newParentId;

            if (!$this->validateHierarchy($item)) {
                $transaction->rollBack();
                $this->moveError = $item->getFirstError('parentId')
                    ?? Craft::t('menu-builder', 'That move isn’t allowed.');

                return false;
            }

            // One snapshot, one plan: what the new parent is and which
            // sortOrders have to change in the destination set and in the
            // set being left. Descendants are deliberately absent from it —
            // they follow their parent by id, so the subtree moves whole.
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
                $this->moveError = Craft::t('menu-builder', 'That move isn’t allowed.');

                return false;
            }

            $this->applySortOrders($plan['sortOrders']);

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to move navigation item: ' . $exception->getMessage(), __METHOD__);
            $this->moveError = Craft::t('menu-builder', 'That move isn’t allowed.');

            return false;
        }

        $this->invalidateGroup($groupId);

        return true;
    }

    /**
     * Why the last {@see move()} was refused, so the CP can say which rule
     * was broken instead of a generic failure.
     */
    public function getLastMoveError(): ?string
    {
        return $this->moveError;
    }

    /**
     * Persists an explicit sibling order (e.g. after a same-parent drag or a
     * keyboard up/down move) without touching parentId. Shares move()'s
     * reconciliation and renumbering, so a stale or tampered id list can only
     * ever permute the set it names — never move a row between parents or
     * groups.
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
     * Clones every top-level item (and its descendants) from one group into
     * another, preserving hierarchy and titles. Used by
     * MenuBuilderGroupService::duplicate() — must run inside that method's
     * own transaction.
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
     * IDs of items in the group whose linked element (entry/category/asset)
     * no longer exists — e.g. hard-deleted rather than soft-deleted/disabled,
     * which `ElementLinkResolver` already handles via `fallbackBehavior`.
     * Batched into at most one query per element type (never per item), so
     * this stays cheap regardless of group size.
     *
     * @return array<int,true> Item IDs, as a set for O(1) lookup in Twig.
     */
    public function getOrphanedItemIds(int $groupId): array
    {
        $elementClasses = [
            MenuBuilderItem::TYPE_ENTRY => Entry::class,
            MenuBuilderItem::TYPE_CATEGORY => Category::class,
            MenuBuilderItem::TYPE_ASSET => Asset::class,
        ];

        $orphaned = [];

        foreach ($elementClasses as $type => $elementClass) {
            $rows = MenuBuilderItemRecord::find()
                ->select(['id', 'elementId'])
                ->where(['groupId' => $groupId, 'type' => $type])
                ->andWhere(['not', ['elementId' => null]])
                ->asArray()
                ->all();

            if (empty($rows)) {
                continue;
            }

            $elementIdToItemIds = [];
            foreach ($rows as $row) {
                $elementIdToItemIds[(int)$row['elementId']][] = (int)$row['id'];
            }

            $existingElementIds = $elementClass::find()
                ->id(array_keys($elementIdToItemIds))
                ->site('*')
                ->unique()
                ->status(null)
                ->select(['elements.id'])
                ->column();
            $existingElementIds = array_map('intval', $existingElementIds);

            foreach ($elementIdToItemIds as $elementId => $itemIds) {
                if (!in_array($elementId, $existingElementIds, true)) {
                    foreach ($itemIds as $itemId) {
                        $orphaned[$itemId] = true;
                    }
                }
            }
        }

        return $orphaned;
    }

    /**
     * Groups containing at least one enabled `dynamic` item — a single
     * indexed query on `type`. A newly-created entry/category/asset can't be
     * matched by `elementId` the way
     * MenuBuilderElementService::getAffectedGroupIds() matches an edited one,
     * so dynamic items have to be considered on every watched element change.
     * {@see getDynamicSourceConfigsByGroup()} narrows that to the items whose
     * source could actually contain the element; this coarser list is the
     * fail-open path for an element whose container can't be determined (e.g.
     * a nested entry, whose `sectionId` is null). Empty — no behaviour change
     * at all — when the install has no dynamic items.
     *
     * @return int[] Distinct group IDs.
     */
    public function getGroupIdsWithDynamicItems(): array
    {
        return array_map(
            'intval',
            MenuBuilderItemRecord::find()
                ->select(['groupId'])
                ->distinct()
                ->where(['type' => MenuBuilderItem::TYPE_DYNAMIC, 'enabled' => true])
                ->column()
        );
    }

    /**
     * Every enabled `dynamic` item's stored source config, grouped by group
     * ID — one query on the same indexed `type` column
     * {@see getGroupIdsWithDynamicItems()} uses, with `metadata` selected so
     * the caller can decide *which* dynamic sources a changed element could
     * belong to, instead of invalidating every group that has any dynamic
     * item.
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
     * Bulk actions. Every ID is independently existence/group-
     * checked and saved through the same {@see save()} path a single toggle
     * uses (so hierarchy/validation rules are never bypassed for a bulk
     * op) inside one transaction — a failure on any ID rolls back the whole
     * batch rather than leaving a partial bulk change applied.
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

    /**
     * @param int[] $ids
     */
    public function bulkDelete(array $ids): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($ids as $id) {
                // deleteById() already invalidates the owning group's cache
                // and relies on the cascading FK for descendants — a bulk
                // delete is just that, repeated inside one transaction.
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
     * An item's groupId is fixed at creation. Reassigning it isn't a
     * supported feature: children keep their own (unchanged) groupId, so
     * moving only the parent would silently detach them from the tree —
     * they'd become orphaned roots in the old group and vanish from the
     * new one. $original is null for a not-yet-persisted item, which is
     * always allowed to pick its group.
     */
    public static function isGroupChangeAllowed(?int $original, int $requested): bool
    {
        return $original === null || $original === $requested;
    }

    /**
     * Circular-reference, cross-group, and max-depth checks. Always runs
     * server-side — the CP's drag-and-drop client validation is UX-only.
     *
     * The whole group is read once here (one indexed query) and every rule
     * is computed from that snapshot by {@see MenuBuilderHierarchyHelper},
     * rather than walking the ancestor chain a query at a time. move() calls
     * it from inside its locked transaction, so what is validated is what
     * gets written.
     */
    private function validateHierarchy(MenuBuilderItem $item): bool
    {
        if ($item->parentId !== null) {
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
        }

        $rows = $this->snapshotForGroup($item->groupId);
        $parentMap = MenuBuilderHierarchyHelper::parentMap($rows);
        $childMap = MenuBuilderHierarchyHelper::childMap($rows);

        if ($item->parentId !== null) {
            // A cycle already present in the stored rows — two moves that
            // each validated against the other's pre-commit state, or a row
            // edited straight in the database — makes every depth answer
            // below meaningless, so fail closed rather than nest anything
            // into it.
            if (MenuBuilderHierarchyHelper::ancestryIsCyclic($parentMap, $item->parentId)) {
                $item->addError('parentId', Craft::t('menu-builder', 'That move would create a circular reference.'));

                return false;
            }

            if ($item->id !== null && MenuBuilderHierarchyHelper::wouldCreateCycle($parentMap, $item->id, $item->parentId)) {
                $item->addError('parentId', Craft::t('menu-builder', 'That move would create a circular reference.'));

                return false;
            }
        }

        $group = MenuBuilder::getInstance()->groups->getById($item->groupId);

        if ($group !== null && $group->maxDepth !== null) {
            // Measured against the subtree's deepest row, not the item's own
            // level, because the descendants travel with it. This runs for a
            // move to the root too: a three-level subtree lifted to the top
            // of a two-level menu still busts the limit, and skipping the
            // check whenever parentId was null let exactly that through.
            $deepestLevel = MenuBuilderHierarchyHelper::deepestLevelAfterMove(
                $parentMap,
                $childMap,
                $item->id ?? 0,
                $item->parentId
            );

            if (!$group->allowsDepth($deepestLevel)) {
                $item->addError('parentId', Craft::t('menu-builder', 'That move exceeds this group\'s maximum nesting depth.'));

                return false;
            }
        }

        return true;
    }

    /**
     * One group's hierarchy columns — everything
     * {@see MenuBuilderHierarchyHelper} needs and nothing else. Covered by
     * the `groupId, parentId, sortOrder` index, so this stays a single cheap
     * read even for a 500-item menu.
     *
     * @return array<int,array{id:int,parentId:int|null,sortOrder:int}>
     */
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
     * Serialises every mutation of one group's hierarchy behind a row lock
     * on the group itself.
     *
     * Validation that ran before a transaction can only prove a move was
     * legal against a state that may no longer exist by the time it commits
     * — two concurrent drags in the same menu are enough to build a cycle
     * out of two individually valid moves, or to interleave two sibling
     * renumberings into a set with duplicate positions. Locking the parent
     * row makes them take turns, so each validates against the other's
     * committed result.
     *
     * No-ops outside a transaction, and on any driver without
     * `SELECT … FOR UPDATE` (Craft 5 ships MySQL and Postgres, which both
     * have it).
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
     * Writes a batch of `sortOrder` values as one statement per chunk
     * (`CASE id WHEN … THEN …`) instead of one UPDATE per row, so
     * repositioning inside a 500-item sibling set costs a couple of queries
     * rather than 500 round trips inside a held lock.
     *
     * Only rows whose value actually changes are passed in (see
     * {@see MenuBuilderHierarchyHelper::sortOrderAssignments()}), which also
     * keeps a drag from rewriting rows nobody touched.
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
     * Authoritative (uncached) existence check for the owning group — a
     * group deleted after this request's group cache was warmed must not
     * still look present. {@see save()} is the only caller; it needs the
     * truth at write time, not at read time.
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

        // Must throw rather than return a half-made clone: this runs inside
        // a transaction, and a failed save leaves $clone->id null — every
        // descendant below would then be written with parentId = null,
        // committing the copied subtree as a pile of orphaned root items.
        if (!$clone->save(false)) {
            throw new Exception('Couldn’t duplicate navigation item ' . $original->id . '.');
        }

        // Ordered, so the copy's sibling order matches the original's —
        // nextSortOrder() numbers each child as it's written, so an
        // unordered query hands the clone whatever order the database felt
        // like returning.
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

    private function invalidateGroup(int $groupId): void
    {
        $handle = MenuBuilder::getInstance()->groups->getHandleById($groupId);

        if ($handle !== null) {
            MenuBuilder::getInstance()->cache->invalidateGroup($handle);
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
        $item->uid = $record->uid;
        $item->dateCreated = $record->dateCreated;
        $item->dateUpdated = $record->dateUpdated;

        return $item;
    }
}

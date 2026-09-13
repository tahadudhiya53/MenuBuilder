<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use Tahadudhiya\MenuBuilder\elements\MenuBuilderItemContent;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderHierarchyHelper;
use Tahadudhiya\MenuBuilder\helpers\TextHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\records\MenuBuilderGroupRecord;

/**
 * Owns navigation-group persistence and business logic.
*/
class MenuBuilderGroupService extends Component
{
    /**
     * Key the group's site restriction lives under inside the `settings` JSON column — see
     * MenuBuilderGroup::$siteIds.
    */
    public const SITE_IDS_KEY = 'siteIds';

    /**
     * Length of the `name`/`handle`/`cssClass` columns (see the Install migration).
    */
    private const MAX_STRING_LENGTH = 255;

    private const CREATION_LOCK = 'menu-builder:create-menu';

    /** @var MenuBuilderGroup[]|null */
    private ?array $allCache = null;

    public function getCount(): int
    {
        return (int)MenuBuilderGroupRecord::find()->count();
    }

    /** @return MenuBuilderGroup[] */
    public function getAll(bool $includeDisabled = true): array
    {
        if ($this->allCache === null) {
            /** @var MenuBuilderGroupRecord[] $records */
            $records = MenuBuilderGroupRecord::find()->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])->all();
            $this->allCache = array_map(fn(MenuBuilderGroupRecord $record) => $this->recordToModel($record), $records);
        }

        if ($includeDisabled) {
            return $this->allCache;
        }

        return array_values(array_filter($this->allCache, fn(MenuBuilderGroup $group) => $group->enabled));
    }

    /**
     * Served from the same memoized `getAll()` list as {@see getByHandle()} and {@see getByUid()},
     * and consistent for the same reason: every write path in this class clears `$allCache`, so the
     * memo can never outlive the state it describes within a request.
    */
    public function getById(int $id): ?MenuBuilderGroup
    {
        foreach ($this->getAll() as $group) {
            if ((int)$group->id === $id) {
                return $group;
            }
        }

        return null;
    }

    public function getByHandle(string $handle): ?MenuBuilderGroup
    {
        foreach ($this->getAll() as $group) {
            if ($group->handle === $handle) {
                return $group;
            }
        }

        return null;
    }

    /**
     * A menu by its UID — the identity {@see \Tahadudhiya\MenuBuilder\fields\MenuBuilderField}
     * persists, because a field value has to survive a handle rename, and because a row ID
     * identifies a menu only within the database that assigned it.
    */
    public function getByUid(string $uid): ?MenuBuilderGroup
    {
        foreach ($this->getAll() as $group) {
            if ($group->uid === $uid) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The edition ceiling is enforced here rather than in the controller, and only for a *new*
     * menu: this is the one path a menu can be created through (see {@see
     * MenuBuilderMenuLimitService}), so a direct POST to `menu-builder/groups/save`, a console
     * command or third-party code all meet the same refusal.
    */
    public function save(MenuBuilderGroup $group, bool $runValidation = true): bool
    {
        if ($group->id) {
            return $this->saveGroup($group, $runValidation);
        }

        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::CREATION_LOCK, 15)) {
            $group->addError('name', Craft::t('menu-builder', 'Another menu is being created. Please try again.'));

            return false;
        }

        try {
            return $this->saveGroup($group, $runValidation);
        } finally {
            $mutex->release(self::CREATION_LOCK);
        }
    }

    private function saveGroup(MenuBuilderGroup $group, bool $runValidation): bool
    {
        if ($runValidation && !$group->validate()) {
            return false;
        }

        if (!$group->id && !MenuBuilder::getInstance()->menuLimit->canCreateMenu()) {
            $group->addError('name', MenuBuilderMenuLimitService::limitMessage());

            return false;
        }

        $record = $group->id
            ? MenuBuilderGroupRecord::findOne($group->id)
            : new MenuBuilderGroupRecord();

        if (!$record) {
            $group->addError('id', Craft::t('menu-builder', 'Navigation group not found.'));

            return false;
        }

        $existing = MenuBuilderGroupRecord::find()
            ->where(['handle' => $group->handle])
            ->andWhere(['not', ['id' => $group->id]])
            ->exists();

        if ($existing) {
            $group->addError('handle', Craft::t('menu-builder', 'That handle is already in use.'));

            return false;
        }

        $isNew = $record->id === null;

        $record->name = $group->name;
        $record->handle = $group->handle;
        $record->description = $group->description;
        $record->enabled = $group->enabled;
        $record->sortOrder = $isNew ? $this->nextSortOrder() : $record->sortOrder;
        $record->maxDepth = $group->maxDepth;
        $record->cssClass = $group->cssClass;
        $record->htmlAttributes = Json::encode($group->htmlAttributes);
        $record->settings = Json::encode($this->settingsWithSiteIds($group->settings, $group->siteIds));

        $record->fieldLayoutId = $this->saveFieldLayout($group);

        if (!$record->save()) {
            $group->addErrors($record->getErrors());

            return false;
        }

        $group->id = $record->id;
        $group->uid = $record->uid;
        $this->allCache = null;
        // This menu only — a menu save must never flush another menu's cache.
        MenuBuilder::getInstance()->cache->invalidateGroupId((int)$record->id);

        return true;
    }

    public function countItems(int $groupId): int
    {
        return MenuBuilder::getInstance()->items->countForGroup($groupId);
    }

    /**
     * Clones a group (name + " Copy", handle uniquified with a numeric suffix) and every item in
     * it, preserving hierarchy.
    */
    public function duplicate(int $id): ?MenuBuilderGroup
    {
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::CREATION_LOCK, 15)) {
            return null;
        }

        try {
            return $this->duplicateGroup($id);
        } finally {
            $mutex->release(self::CREATION_LOCK);
        }
    }

    private function duplicateGroup(int $id): ?MenuBuilderGroup
    {
        $original = MenuBuilderGroupRecord::findOne($id);

        if (!$original) {
            return null;
        }

        // A duplicate is a new menu, so it meets the same edition ceiling {@see save()} does —
        // this is the second (and last) way a row can reach `menubuilder_groups`.
        if (!MenuBuilder::getInstance()->menuLimit->canCreateMenu()) {
            return null;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $clone = new MenuBuilderGroupRecord();
            $clone->name = TextHelper::truncate($original->name . ' Copy', self::MAX_STRING_LENGTH);
            $clone->handle = $this->uniqueHandle($original->handle);
            $clone->description = $original->description;
            $clone->enabled = $original->enabled;
            $clone->sortOrder = $this->nextSortOrder();
            $clone->maxDepth = $original->maxDepth;
            $clone->cssClass = $original->cssClass;
            $clone->htmlAttributes = $original->htmlAttributes;
            $clone->settings = $original->settings;
            // A *copy* of the layout, never a shared id: two menus pointing at one `fieldlayouts`
            // row would mean editing either menu's fields silently rewrote the other's.
            $clone->fieldLayoutId = $this->duplicateFieldLayout($original->fieldLayoutId);

            if (!$clone->save()) {
                $transaction->rollBack();

                return null;
            }

            MenuBuilder::getInstance()->items->duplicateAllForGroup((int)$original->id, (int)$clone->id);
            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to duplicate navigation group: ' . $exception->getMessage(), __METHOD__);

            return null;
        }

        $this->allCache = null;
        // Only the clone can have anything cached: the original is unchanged by a duplicate, and
        // the clone's handle is new.
        MenuBuilder::getInstance()->cache->invalidateGroupId((int)$clone->id);

        return $this->recordToModel($clone);
    }

    /**
     * The base handle is trimmed before the numeric suffix is appended, so duplicating a group
     * whose handle already fills the column produces a valid shorter handle rather than an
     * over-long one the database rejects (or silently truncates into a collision).
    */
    private function uniqueHandle(string $baseHandle): string
    {
        $handle = TextHelper::truncate($baseHandle, self::MAX_STRING_LENGTH);
        $suffix = 2;

        while (MenuBuilderGroupRecord::find()->where(['handle' => $handle])->exists()) {
            $handle = self::suffixedHandle($baseHandle, $suffix);
            $suffix++;
        }

        return $handle;
    }

    /**
     * `$baseHandle` with `$suffix` appended, trimmed so the result still fits the column.
    */
    private static function suffixedHandle(string $baseHandle, int $suffix): string
    {
        $suffixString = (string)$suffix;

        return TextHelper::truncate($baseHandle, self::MAX_STRING_LENGTH - strlen($suffixString)) . $suffixString;
    }

    public function deleteById(int $id): bool
    {
        $record = MenuBuilderGroupRecord::findOne($id);

        if (!$record) {
            return false;
        }

        // The items' content elements first, while the item rows that name them still exist: the
        // cascading FK below removes those rows without passing through PHP, and an `elements` row
        // is Craft's to delete, not something a foreign key may strand.
        MenuBuilder::getInstance()->items->deleteContentForGroup($id);

        $fieldLayoutId = $record->fieldLayoutId;

        // Cascading FK on menubuilder_items.groupId removes every item in the group, so no orphans
        // are left behind and no PHP-side sweep is needed.
        $result = (bool)$record->delete();

        if ($result && $fieldLayoutId !== null) {
            // Nothing else can be pointing at it — a layout belongs to exactly one menu (see
            // duplicate()) — so deleting the menu deletes its layout rather than leaving an
            // unreachable row behind for every menu ever deleted.
            Craft::$app->getFields()->deleteLayoutById((int)$fieldLayoutId);
        }
        $this->allCache = null;
        // The deleted menu's entries only.
        MenuBuilder::getInstance()->cache->invalidateGroupId($id);

        return $result;
    }

    /**
     * Persists an explicit menu order.
     *
     * The posted order is treated as a **preference, not as truth** — exactly as a drag's
     * `siblingIds` are on the item side ({@see MenuBuilderItemService::reorderSiblings()}): it is a
     * snapshot of one editor's screen, which may name a menu somebody has since deleted, omit one
     * created since the page loaded, or repeat an id. `MenuBuilderHierarchyHelper::resolveSiblingOrder()`
     * reconciles it against the real set, so what is written is always a permutation of the menus
     * that actually exist, which is what keeps the resulting `sortOrder` values gap-free.
     *
     * No cache invalidation: `sortOrder` decides the order menus are *listed* in, and a menu's
     * cached tree is keyed by its own id, handle and `dateUpdated` (see MenuBuilderCacheService).
     * Nothing about a resolved tree changes when the list is re-ordered.
     *
     * @param int[] $groupIdsInOrder
    */
    public function reorder(array $groupIdsInOrder): bool
    {
        $current = array_map(static fn(MenuBuilderGroup $group): int => (int)$group->id, $this->getAll());
        $ordered = MenuBuilderHierarchyHelper::resolveSiblingOrder($current, $groupIdsInOrder);
        $sortOrders = [];

        foreach ($this->getAll() as $group) {
            $sortOrders[(int)$group->id] = (int)$group->sortOrder;
        }

        $assignments = MenuBuilderHierarchyHelper::sortOrderAssignments($ordered, $sortOrders);

        if ($assignments === []) {
            return true;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($assignments as $groupId => $sortOrder) {
                MenuBuilderGroupRecord::updateAll(['sortOrder' => $sortOrder], ['id' => $groupId]);
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            Craft::warning('Failed to reorder navigation groups: ' . $exception->getMessage(), __METHOD__);

            return false;
        }

        $this->allCache = null;

        return true;
    }

    private function nextSortOrder(): int
    {
        $max = MenuBuilderGroupRecord::find()->max('sortOrder');

        return $max === null ? 0 : ((int)$max + 1);
    }

    private function recordToModel(MenuBuilderGroupRecord $record): MenuBuilderGroup
    {
        $group = new MenuBuilderGroup();
        $group->id = $record->id;
        $group->name = $record->name;
        $group->handle = $record->handle;
        $group->description = $record->description;
        $group->enabled = (bool)$record->enabled;
        $group->sortOrder = (int)$record->sortOrder;
        $group->maxDepth = $record->maxDepth !== null ? (int)$record->maxDepth : null;
        $group->cssClass = $record->cssClass;
        $group->htmlAttributes = ConfigHelper::decodeJsonBag($record->htmlAttributes);
        $settings = ConfigHelper::decodeJsonBag($record->settings);
        $group->siteIds = ConfigHelper::normalizeIdList($settings[self::SITE_IDS_KEY] ?? null);
        unset($settings[self::SITE_IDS_KEY]);
        $group->settings = $settings;
        $group->fieldLayoutId = $record->fieldLayoutId !== null ? (int)$record->fieldLayoutId : null;
        $group->uid = $record->uid;
        $group->dateCreated = $record->dateCreated;
        $group->dateUpdated = $record->dateUpdated;

        return $group;
    }

    /**
     * @param array<string,mixed> $settings
     * @param int[] $siteIds
     * @return array<string,mixed>
    */
    private function settingsWithSiteIds(array $settings, array $siteIds): array
    {
        $siteIds = ConfigHelper::normalizeIdList($siteIds);

        if (empty($siteIds)) {
            unset($settings[self::SITE_IDS_KEY]);

            return $settings;
        }

        $settings[self::SITE_IDS_KEY] = $siteIds;

        return $settings;
    }

    /**
     * A new `fieldlayouts` row with the same tabs and field configuration as `$sourceId`, or null
     * when there is nothing to copy.
    */
    /**
     * Persists a menu's field layout and returns the id to store, or null when the menu has no
     * fields.
    */
    private function saveFieldLayout(MenuBuilderGroup $group): ?int
    {
        $fieldLayout = $group->getFieldLayout();
        $fieldLayout->id = $group->fieldLayoutId;
        $fieldLayout->type = MenuBuilderItemContent::class;

        if ($fieldLayout->getCustomFields() === []) {
            if ($group->fieldLayoutId !== null) {
                Craft::$app->getFields()->deleteLayoutById($group->fieldLayoutId);
            }

            $group->fieldLayoutId = null;
            $group->setFieldLayout(new FieldLayout(['type' => MenuBuilderItemContent::class]));

            return null;
        }

        Craft::$app->getFields()->saveLayout($fieldLayout);
        $group->fieldLayoutId = $fieldLayout->id;

        return $fieldLayout->id;
    }

    private function duplicateFieldLayout(?int $sourceId): ?int
    {
        if ($sourceId === null) {
            return null;
        }

        $source = Craft::$app->getFields()->getLayoutById((int)$sourceId);
        $config = $source?->getConfig();

        if ($config === null) {
            // Either the layout is gone, or it holds no tabs — `getConfig()` returns null for an
            // empty one.
            return null;
        }

        // Fresh UIDs throughout.
        $clone = FieldLayout::createFromConfig(self::withFreshUids($config));
        $clone->uid = StringHelper::UUID();
        $clone->type = MenuBuilderItemContent::class;

        return Craft::$app->getFields()->saveLayout($clone) ? $clone->id : null;
    }

    /**
     * `$config` with every `uid` replaced by a new one, at any depth.
     *
     * @param array<mixed,mixed> $config
     * @return array<mixed,mixed>
    */
    private static function withFreshUids(array $config): array
    {
        foreach ($config as $key => $value) {
            if ($key === 'uid' && is_string($value)) {
                $config[$key] = StringHelper::UUID();
            } elseif (is_array($value)) {
                $config[$key] = self::withFreshUids($value);
            }
        }

        return $config;
    }
}

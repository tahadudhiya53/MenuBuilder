<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\helpers\CustomFieldHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderCustomField;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\records\MenuBuilderGroupRecord;

/**
 * Owns navigation-group persistence and business logic. The database is the
 * **single source of truth** for group configuration: every read and every
 * write goes through `menubuilder_groups`, and nothing about a group is
 * mirrored into, synchronized with, or applied from Craft's project config.
 * That's a deliberate architectural decision — a second store would mean
 * database/YAML drift, `project-config/apply` overwriting live edits,
 * sortOrder and uid synchronization, and a rebuild path to keep honest, none
 * of which buys anything for data editors manage in the CP.
 *
 * Controllers never touch MenuBuilderGroupRecord directly; validation
 * (MenuBuilderGroup), handle uniqueness, transactions and cache
 * invalidation all live here, and reaching past this class skips them.
 */
class MenuBuilderGroupService extends Component
{
    /**
     * Key the group's site restriction lives under inside the `settings`
     * JSON column — see MenuBuilderGroup::$siteIds. Kept in the existing
     * open-ended bag rather than a new column so no migration is needed;
     * recordToModel() lifts it back out so `$group->settings` stays a plain
     * user-facing bag.
     */
    public const SITE_IDS_KEY = 'siteIds';

    /**
     * Key the menu's custom field *definitions* live under inside the same
     * `settings` bag — see MenuBuilderGroup::$customFields and
     * {@see CustomFieldHelper}. Same reasoning as {@see SITE_IDS_KEY}: an
     * existing open-ended bag rather than a new column or a second settings
     * system, lifted back out on read so `$group->settings` stays a plain
     * user-facing bag.
     */
    public const CUSTOM_FIELDS_KEY = 'customFields';

    /**
     * Length of the `name`/`handle`/`cssClass` columns (see the Install
     * migration). {@see MenuBuilderGroup} rejects anything longer on the
     * user-facing path; {@see duplicate()} derives new values from existing
     * ones instead, so it has to trim them itself.
     */
    private const MAX_STRING_LENGTH = 255;

    /** @var MenuBuilderGroup[]|null */
    private ?array $allCache = null;

    /**
     * @return MenuBuilderGroup[]
     */
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

    public function getById(int $id): ?MenuBuilderGroup
    {
        $record = MenuBuilderGroupRecord::findOne($id);

        return $record ? $this->recordToModel($record) : null;
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

    public function save(MenuBuilderGroup $group, bool $runValidation = true): bool
    {
        if ($runValidation && !$group->validate()) {
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
        $record->settings = Json::encode($this->settingsWithCustomFields(
            $this->settingsWithSiteIds($group->settings, $group->siteIds),
            $group->customFields
        ));

        if (!$record->save()) {
            $group->addErrors($record->getErrors());

            return false;
        }

        $group->id = $record->id;
        $group->uid = $record->uid;
        $this->allCache = null;
        // This menu only — a menu save must never flush another menu's
        // cache. Invalidating by ID is what makes a *rename* safe: cache
        // entries are tagged per menu ID, not per handle, so the entries
        // written under the old handle are reached too (see
        // MenuBuilderCacheService).
        MenuBuilder::getInstance()->cache->invalidateGroupId((int)$record->id);

        return true;
    }

    public function countItems(int $groupId): int
    {
        return MenuBuilder::getInstance()->items->countForGroup($groupId);
    }

    /**
     * Clones a group (name + " Copy", handle uniquified with a numeric
     * suffix) and every item in it, preserving hierarchy.
     */
    public function duplicate(int $id): ?MenuBuilderGroup
    {
        $original = MenuBuilderGroupRecord::findOne($id);

        if (!$original) {
            return null;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $clone = new MenuBuilderGroupRecord();
            $clone->name = self::truncate($original->name . ' Copy', self::MAX_STRING_LENGTH);
            $clone->handle = $this->uniqueHandle($original->handle);
            $clone->description = $original->description;
            $clone->enabled = $original->enabled;
            $clone->sortOrder = $this->nextSortOrder();
            $clone->maxDepth = $original->maxDepth;
            $clone->cssClass = $original->cssClass;
            $clone->htmlAttributes = $original->htmlAttributes;
            $clone->settings = $original->settings;

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
        // Only the clone can have anything cached: the original is unchanged
        // by a duplicate, and the clone's handle is new. Not a no-op in the
        // one case that matters — a handle freed by an earlier delete and
        // picked up again here.
        MenuBuilder::getInstance()->cache->invalidateGroupId((int)$clone->id);

        return $this->recordToModel($clone);
    }

    /**
     * The base handle is trimmed before the numeric suffix is appended, so
     * duplicating a group whose handle already fills the column produces a
     * valid shorter handle rather than an over-long one the database
     * rejects (or silently truncates into a collision).
     */
    private function uniqueHandle(string $baseHandle): string
    {
        $handle = self::truncate($baseHandle, self::MAX_STRING_LENGTH);
        $suffix = 2;

        while (MenuBuilderGroupRecord::find()->where(['handle' => $handle])->exists()) {
            $handle = self::suffixedHandle($baseHandle, $suffix);
            $suffix++;
        }

        return $handle;
    }

    /**
     * `$baseHandle` with `$suffix` appended, trimmed so the result still
     * fits the column. Pure, so the length arithmetic {@see uniqueHandle()}
     * depends on is testable without a database.
     */
    private static function suffixedHandle(string $baseHandle, int $suffix): string
    {
        $suffixString = (string)$suffix;

        return self::truncate($baseHandle, self::MAX_STRING_LENGTH - strlen($suffixString)) . $suffixString;
    }

    private static function truncate(string $value, int $length): string
    {
        return strlen($value) > $length ? substr($value, 0, $length) : $value;
    }

    public function deleteById(int $id): bool
    {
        $record = MenuBuilderGroupRecord::findOne($id);

        if (!$record) {
            return false;
        }

        // Cascading FK on menubuilder_items.groupId removes every item in the
        // group, so no orphans are left behind and no PHP-side sweep is needed.
        $result = (bool)$record->delete();
        $this->allCache = null;
        // The deleted menu's entries only. They are already unreachable by
        // key — nothing resolves the handle any more — but the handle is now
        // free for a new menu to take, and that menu must not read what this
        // one left behind.
        MenuBuilder::getInstance()->cache->invalidateGroupId($id);

        return $result;
    }

    /**
     * Persists an explicit menu order. `sortOrder` is database-only — there
     * is no second copy anywhere to keep in step — so the transaction below
     * is the whole write.
     */
    public function reorder(array $groupIdsInOrder): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($groupIdsInOrder as $index => $groupId) {
                MenuBuilderGroupRecord::updateAll(['sortOrder' => $index], ['id' => (int)$groupId]);
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
        // Fail-closed: a malformed definition (an import, a hand-written
        // row) is dropped here rather than reaching the item editor or a
        // template as a field with a guessed type.
        $group->customFields = CustomFieldHelper::definitionsFromConfig($settings[self::CUSTOM_FIELDS_KEY] ?? null);
        unset($settings[self::SITE_IDS_KEY]);
        unset($settings[self::CUSTOM_FIELDS_KEY]);
        $group->settings = $settings;
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
     * The custom field half of the same fold, kept separate from
     * {@see settingsWithSiteIds()} so each reserved key is one small pure
     * function. Removed when the menu defines none, rather than written as
     * `[]`, so a menu that uses no custom fields stores exactly the bag the
     * user sees.
     *
     * @param array<string,mixed> $settings
     * @param MenuBuilderCustomField[] $customFields
     * @return array<string,mixed>
     */
    private function settingsWithCustomFields(array $settings, array $customFields): array
    {
        $config = CustomFieldHelper::definitionsToConfig($customFields);

        if (empty($config)) {
            unset($settings[self::CUSTOM_FIELDS_KEY]);

            return $settings;
        }

        $settings[self::CUSTOM_FIELDS_KEY] = $config;

        return $settings;
    }
}

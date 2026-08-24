<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\events\ConfigEvent;
use craft\helpers\Json;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\records\MenuBuilderGroupRecord;

/**
 * Phase 10 project config: the database record remains authoritative for
 * every locally-initiated save/delete in this class (zero change to the
 * already-hardened Phase 1/5 write path) — after a successful write, the
 * current state is *mirrored* into project config under
 * {@see CONFIG_PATH}.`{uid}` so it's captured in `project.yaml` and
 * portable/diffable across environments, the same way Craft's own
 * structural resources (Sections, Fields, …) are. `handleChangedConfig()`/
 * `handleDeletedConfig()` (registered by MenuBuilder::attachEventHandlers())
 * handle the *other* direction — applying a config change that arrived from
 * project.yaml (e.g. after a deploy pulls a teammate's change) to the local
 * database.
 */
class MenuBuilderGroupService extends Component
{
    public const CONFIG_PATH = 'menuBuilder.groups';

    /**
     * Key the group's site restriction lives under inside the `settings`
     * JSON column — see MenuBuilderGroup::$siteIds. Kept in the existing
     * open-ended bag rather than a new column so no migration (and no
     * project-config schema bump) is needed; recordToModel() lifts it back
     * out so `$group->settings` stays a plain user-facing bag.
     */
    public const SITE_IDS_KEY = 'siteIds';

    /** @var MenuBuilderGroup[]|null */
    private ?array $allCache = null;

    /**
     * @return MenuBuilderGroup[]
     */
    public function getAll(bool $includeDisabled = true): array
    {
        if ($this->allCache === null) {
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
        $record->settings = Json::encode($this->settingsWithSiteIds($group->settings, $group->siteIds));

        if (!$record->save()) {
            $group->addErrors($record->getErrors());

            return false;
        }

        $group->id = $record->id;
        $group->uid = $record->uid;
        $this->allCache = null;
        MenuBuilder::getInstance()->cache->invalidateAll();
        $this->mirrorToProjectConfig($record);

        return true;
    }

    /**
     * Writes/updates the config path from a *local* database change. Craft
     * only fires the `onAdd`/`onUpdate` handlers registered on this same
     * path (see MenuBuilder::attachEventHandlers()) when the new value
     * actually differs from what's currently stored, so this doesn't cause
     * an immediate redundant re-save via handleChangedConfig() for the
     * common case of no other pending change.
     */
    private function mirrorToProjectConfig(MenuBuilderGroupRecord $record): void
    {
        Craft::$app->getProjectConfig()->set(
            self::CONFIG_PATH . '.' . $record->uid,
            $this->configDataFromRecord($record),
            "Save navigation group “{$record->name}”"
        );
    }

    private function configDataFromRecord(MenuBuilderGroupRecord $record): array
    {
        return [
            'name' => $record->name,
            'handle' => $record->handle,
            'description' => $record->description,
            'enabled' => (bool)$record->enabled,
            'sortOrder' => (int)$record->sortOrder,
            'maxDepth' => $record->maxDepth !== null ? (int)$record->maxDepth : null,
            'cssClass' => $record->cssClass,
            'htmlAttributes' => $this->decodeArray($record->htmlAttributes),
            'settings' => $this->decodeArray($record->settings),
        ];
    }

    /**
     * Applies a group config change that originated from project.yaml
     * (not from {@see save()} in this same request — see that method's
     * docblock) to the local database. Registered on
     * `ProjectConfig::EVENT_...` via `onAdd`/`onUpdate` in
     * MenuBuilder::attachEventHandlers().
     */
    public function handleChangedConfig(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0] ?? null;
        $data = $event->newValue;

        if (!is_string($uid) || !is_array($data)) {
            return;
        }

        $record = MenuBuilderGroupRecord::find()->where(['uid' => $uid])->one() ?? new MenuBuilderGroupRecord(['uid' => $uid]);
        $isNew = $record->id === null;

        $record->name = (string)($data['name'] ?? $data['handle'] ?? $uid);
        $record->handle = (string)($data['handle'] ?? '');
        $record->description = $data['description'] ?? null;
        $record->enabled = (bool)($data['enabled'] ?? true);
        $record->sortOrder = $isNew ? ($data['sortOrder'] ?? $this->nextSortOrder()) : $record->sortOrder;
        $record->maxDepth = isset($data['maxDepth']) ? (int)$data['maxDepth'] : null;
        $record->cssClass = $data['cssClass'] ?? null;
        $record->htmlAttributes = Json::encode(is_array($data['htmlAttributes'] ?? null) ? $data['htmlAttributes'] : []);
        $record->settings = Json::encode(is_array($data['settings'] ?? null) ? $data['settings'] : []);

        if (!$record->save(false)) {
            Craft::warning('Failed to apply navigation group config change for uid ' . $uid, __METHOD__);

            return;
        }

        $this->allCache = null;
        MenuBuilder::getInstance()->cache->invalidateAll();
    }

    /** Registered on `ProjectConfig::EVENT_...`'s `onRemove` — deletes the local record matching a removed config entry. */
    public function handleDeletedConfig(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0] ?? null;

        if (!is_string($uid)) {
            return;
        }

        $record = MenuBuilderGroupRecord::find()->where(['uid' => $uid])->one();

        if ($record !== null) {
            $record->delete();
            $this->allCache = null;
            MenuBuilder::getInstance()->cache->invalidateAll();
        }
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
            $clone->name = $original->name . ' Copy';
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
        MenuBuilder::getInstance()->cache->invalidateAll();
        $this->mirrorToProjectConfig($clone);

        return $this->recordToModel($clone);
    }

    private function uniqueHandle(string $baseHandle): string
    {
        $handle = $baseHandle;
        $suffix = 2;

        while (MenuBuilderGroupRecord::find()->where(['handle' => $handle])->exists()) {
            $handle = $baseHandle . $suffix;
            $suffix++;
        }

        return $handle;
    }

    public function deleteById(int $id): bool
    {
        $record = MenuBuilderGroupRecord::findOne($id);

        if (!$record) {
            return false;
        }

        $uid = $record->uid;

        // Cascading FK on menubuilder_items.groupId removes every item in the group.
        $result = (bool)$record->delete();
        $this->allCache = null;
        MenuBuilder::getInstance()->cache->invalidateAll();

        if ($result) {
            Craft::$app->getProjectConfig()->remove(self::CONFIG_PATH . '.' . $uid);
        }

        return $result;
    }

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
        $group->htmlAttributes = $this->decodeArray($record->htmlAttributes);
        $settings = $this->decodeArray($record->settings);
        $group->siteIds = $this->normalizeSiteIds($settings[self::SITE_IDS_KEY] ?? null);
        unset($settings[self::SITE_IDS_KEY]);
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
        $siteIds = $this->normalizeSiteIds($siteIds);

        if (empty($siteIds)) {
            unset($settings[self::SITE_IDS_KEY]);

            return $settings;
        }

        $settings[self::SITE_IDS_KEY] = $siteIds;

        return $settings;
    }

    /**
     * @return int[]
     */
    private function normalizeSiteIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $siteIds = array_filter(array_map('intval', array_filter($value, 'is_scalar')), fn(int $id) => $id > 0);

        return array_values(array_unique($siteIds));
    }

    private function decodeArray(?string $json): array
    {
        if (!$json) {
            return [];
        }

        try {
            $decoded = Json::decode($json);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}

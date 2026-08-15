<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\records\MenuBuilderGroupRecord;

class MenuBuilderGroupService extends Component
{
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
        $record->settings = Json::encode($group->settings);

        if (!$record->save()) {
            $group->addErrors($record->getErrors());

            return false;
        }

        $group->id = $record->id;
        $group->uid = $record->uid;
        $this->allCache = null;
        MenuBuilder::getInstance()->cache->invalidateAll();

        return true;
    }

    public function deleteById(int $id): bool
    {
        $record = MenuBuilderGroupRecord::findOne($id);

        if (!$record) {
            return false;
        }

        // Cascading FK on menubuilder_items.groupId removes every item in the group.
        $result = (bool)$record->delete();
        $this->allCache = null;
        MenuBuilder::getInstance()->cache->invalidateAll();

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
        $group->settings = $this->decodeArray($record->settings);
        $group->uid = $record->uid;
        $group->dateCreated = $record->dateCreated;
        $group->dateUpdated = $record->dateUpdated;

        return $group;
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

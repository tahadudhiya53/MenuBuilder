<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use Tahadudhiya\MenuBuilder\elements\MenuBuilderItemContent;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
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

    /**
     * Served from the same memoized `getAll()` list as {@see getByHandle()}
     * and {@see getByUid()}, and consistent for the same reason: every write
     * path in this class clears `$allCache`, so the memo can never outlive
     * the state it describes within a request.
     *
     * It reads from the memo rather than the database because the write path
     * asks for the same menu more than once per saved item — once for its
     * custom field definitions, once for its `maxDepth`
     * ({@see \Tahadudhiya\MenuBuilder\services\MenuBuilderItemService::save()})
     * — which made a 100-item bulk save cost 200 menu queries.
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
     * persists, because a field value has to survive a handle rename, and
     * because a row ID identifies a menu only within the database that
     * assigned it. Menus remain database-only either way (see this class's
     * docblock): a UID is a stable reference, not a menu that travels.
     *
     * Served from the same memoized `getAll()` list `getByHandle()` uses, so
     * an element index normalizing a hundred field values costs one query,
     * not a hundred.
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
     * The edition ceiling is enforced here rather than in the controller,
     * and only for a *new* menu: this is the one path a menu can be created
     * through (see {@see MenuBuilderMenuLimitService}), so a direct POST to
     * `menu-builder/groups/save`, a console command or third-party code all
     * meet the same refusal. Editing, disabling, reordering or deleting an
     * existing menu is never limited — including on an install that is over
     * the limit because its edition changed.
     *
     * The refusal is reported as a model error rather than an exception so
     * it travels the same way a validation failure does, through
     * `asModelFailure()` to the form the user is looking at.
     */
    public function save(MenuBuilderGroup $group, bool $runValidation = true): bool
    {
        if ($runValidation && !$group->validate()) {
            return false;
        }

        if ($group->id === null && !MenuBuilder::getInstance()->menuLimit->canCreateMenu()) {
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
     * suffix) and every item in it, preserving hierarchy. Refused, like any
     * other menu creation, when the install is at its edition's ceiling.
     */
    public function duplicate(int $id): ?MenuBuilderGroup
    {
        $original = MenuBuilderGroupRecord::findOne($id);

        if (!$original) {
            return null;
        }

        // A duplicate is a new menu, so it meets the same edition ceiling
        // {@see save()} does — this is the second (and last) way a row can
        // reach `menubuilder_groups`.
        if (!MenuBuilder::getInstance()->menuLimit->canCreateMenu()) {
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
            // A *copy* of the layout, never a shared id: two menus pointing
            // at one `fieldlayouts` row would mean editing either menu's
            // fields silently rewrote the other's. `saveLayout()` with the
            // id cleared writes a new row; the tabs and their field
            // configuration are carried over as-is.
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

        // The items' content elements first, while the item rows that name
        // them still exist: the cascading FK below removes those rows
        // without passing through PHP, and an `elements` row is Craft's to
        // delete, not something a foreign key may strand. (The garbage
        // collector sweeps whatever a crash between the two leaves behind.)
        MenuBuilder::getInstance()->items->deleteContentForGroup($id);

        $fieldLayoutId = $record->fieldLayoutId;

        // Cascading FK on menubuilder_items.groupId removes every item in the
        // group, so no orphans are left behind and no PHP-side sweep is needed.
        $result = (bool)$record->delete();

        if ($result && $fieldLayoutId !== null) {
            // Nothing else can be pointing at it — a layout belongs to
            // exactly one menu (see duplicate()) — so deleting the menu
            // deletes its layout rather than leaving an unreachable row
            // behind for every menu ever deleted.
            Craft::$app->getFields()->deleteLayoutById((int)$fieldLayoutId);
        }
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
     * A new `fieldlayouts` row with the same tabs and field configuration as
     * `$sourceId`, or null when there is nothing to copy.
     *
     * Round-tripped through the layout's own config array rather than
     * cloned object-by-object: that array is the shape
     * `Fields::saveLayout()` reads back, so a layout element type this
     * plugin has never heard of (a third-party field, a future Craft one)
     * copies correctly without this method knowing anything about it.
     */
    /**
     * Persists a menu's field layout and returns the id to store, or null
     * when the menu has no fields.
     *
     * Craft's row, not this plugin's, so it has to be written before the
     * menu that points at it — `saveLayout()` is what assigns the id. It
     * reuses the existing id when the menu already has a layout, so editing
     * one never orphans the content already stored against it.
     *
     * An **empty** layout is not saved at all, and an existing one that has
     * just been emptied is deleted. Otherwise every menu ever created would
     * carry a `fieldlayouts` row holding nothing, `hasCustomFields()` would
     * have to distinguish "has a layout" from "has fields" everywhere, and
     * `getConfig()` — which returns null for a layout with no tabs — would
     * hand {@see duplicateFieldLayout()} nothing to copy.
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
            // Either the layout is gone, or it holds no tabs — `getConfig()`
            // returns null for an empty one. Nothing to copy either way, and
            // a menu with no fields is a valid menu.
            return null;
        }

        // Fresh UIDs throughout. A layout, its tabs and its layout elements
        // are all identified by UID, and Craft treats a matching one as the
        // *same* component — so copying them verbatim would either collide
        // on save or quietly re-point the original menu's layout at the
        // clone. Only the identifiers change; every field, condition and
        // setting inside the config is carried over untouched.
        // No `id` is assigned: `createFromConfig()` builds an unsaved
        // layout, and saveLayout() is what gives the copy its own row.
        $clone = FieldLayout::createFromConfig(self::withFreshUids($config));
        $clone->uid = StringHelper::UUID();
        $clone->type = MenuBuilderItemContent::class;

        return Craft::$app->getFields()->saveLayout($clone) ? $clone->id : null;
    }

    /**
     * `$config` with every `uid` replaced by a new one, at any depth.
     *
     * Recursive and key-driven rather than shaped: a layout element's config
     * is whatever that element type defines, so a nested UID belonging to a
     * third-party layout element has to be renewed without this method
     * knowing the element exists.
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

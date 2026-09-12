<?php

namespace Tahadudhiya\MenuBuilder\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $groupId
 * @property int|null $parentId
 * @property string $type
 * @property string $title
 * @property string|null $handle
 * @property bool $enabled
 * @property int $sortOrder
 * @property bool $clickable
 * @property int|null $elementId
 * @property string|null $customUrl
 * @property string $target
 * @property string|null $rel
 * @property string|null $cssClass
 * @property string|null $htmlId
 * @property string $htmlAttributes JSON-encoded.
 * @property string|null $ariaLabel
 * @property string|null $titleAttribute
 * @property string|null $icon
 * @property string|null $badge
 * @property string|null $description
 * @property int|null $image
 * @property bool $featured
 * @property string $fallbackBehavior
 * @property string|null $fallbackUrl
 * @property string $visibility JSON-encoded.
 * @property string $metadata JSON-encoded.
 * @property int|null $contentId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
*/
class MenuBuilderItemRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%menubuilder_items}}';
    }

    /**
     * The distinct `groupId`s of the items matching `$conditions` — the one
     * shape behind every "which menus does this change affect?" question the
     * cache-invalidation path asks.
     *
     * Three callers used to spell the same query out:
     * MenuBuilderElementService::getAffectedGroupIds() (by `elementId`),
     * ::getGroupIdsReferencingContainer() (by `type` + an element sub-query)
     * and MenuBuilderItemService::getGroupIdsWithDynamicItems() (by `type` +
     * `enabled`). All three are single indexed lookups that must never scan
     * per item, and returning ints rather than the driver's strings is part
     * of that contract — a group ID is compared and keyed on by every caller.
     *
     * Conditions are ANDed in the order given, so a caller that needs a
     * sub-query condition can pass it separately from its scalar ones.
     *
     * @param array<mixed,mixed> ...$conditions Yii condition arrays.
     * @return int[] Distinct group IDs.
     */
    public static function distinctGroupIds(array ...$conditions): array
    {
        $query = static::find()
            ->select(['groupId'])
            ->distinct();

        foreach ($conditions as $condition) {
            $query->andWhere($condition);
        }

        return array_map('intval', $query->column());
    }
}

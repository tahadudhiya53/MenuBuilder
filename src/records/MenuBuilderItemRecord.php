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

    public function getGroup(): \yii\db\ActiveQuery
    {
        return $this->hasOne(MenuBuilderGroupRecord::class, ['id' => 'groupId']);
    }

    public function getParent(): \yii\db\ActiveQuery
    {
        return $this->hasOne(self::class, ['id' => 'parentId']);
    }
}

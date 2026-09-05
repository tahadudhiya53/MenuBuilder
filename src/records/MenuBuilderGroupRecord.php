<?php

namespace Tahadudhiya\MenuBuilder\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property bool $enabled
 * @property int $sortOrder
 * @property int|null $maxDepth
 * @property string|null $cssClass
 * @property string $htmlAttributes JSON-encoded.
 * @property string $settings JSON-encoded.
 * @property int|null $fieldLayoutId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class MenuBuilderGroupRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%menubuilder_groups}}';
    }
}

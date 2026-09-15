<?php

namespace Tahadudhiya\MenuBuilder\gql;

use Craft;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\MenuBuilder\models\MenuBuilderFieldValue;

/**
 * The GraphQL shape of a {@see \Tahadudhiya\MenuBuilder\fields\MenuBuilderField} value: the
 * **selection**, not the resolved menu.
*/
class MenuBuilderMenuType
{
    public const NAME = 'MenuBuilderMenu';

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => Craft::t('menubuilder', 'A navigation selected by a MenuBuilder Navigation field.'),
            'fields' => self::fieldDefinitions(),
        ]));
    }

    /**
     * The type's fields, resolvers included, as a plain array.
     *
     * @return array<string,array<string,mixed>>
    */
    public static function fieldDefinitions(): array
    {
        return [
            'uid' => [
                'name' => 'uid',
                'type' => Type::string(),
                'description' => Craft::t('menubuilder', 'The selected navigation’s UID — stable across handle renames and environments.'),
                'resolve' => static fn(MenuBuilderFieldValue $value) => $value->groupUid,
            ],
            'handle' => [
                'name' => 'handle',
                'type' => Type::string(),
                'description' => Craft::t('menubuilder', 'The selected navigation’s handle, or null if it no longer exists.'),
                'resolve' => static fn(MenuBuilderFieldValue $value) => $value->getHandle(),
            ],
            'name' => [
                'name' => 'name',
                'type' => Type::string(),
                'description' => Craft::t('menubuilder', 'The selected navigation’s name, or null if it no longer exists.'),
                'resolve' => static fn(MenuBuilderFieldValue $value) => $value->getName(),
            ],
            'exists' => [
                'name' => 'exists',
                'type' => Type::nonNull(Type::boolean()),
                'description' => Craft::t('menubuilder', 'Whether the selected navigation still exists.'),
                'resolve' => static fn(MenuBuilderFieldValue $value) => $value->exists(),
            ],
            'enabled' => [
                'name' => 'enabled',
                'type' => Type::nonNull(Type::boolean()),
                'description' => Craft::t('menubuilder', 'Whether the selected navigation is enabled. A disabled navigation renders nothing.'),
                'resolve' => static fn(MenuBuilderFieldValue $value) => $value->isEnabled(),
            ],
        ];
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\gql;

use Craft;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\MenuBuilder\models\MenuBuilderFieldValue;

/**
 * The GraphQL shape of a {@see \Tahadudhiya\MenuBuilder\fields\MenuBuilderField}
 * value: the **selection**, not the resolved menu.
 *
 * A resolved tree is per-site, per-visitor and per-page — that is the whole
 * premise of the resolve pipeline (see ARCHITECTURE.md "Caching") — and a
 * GraphQL response is cached and shared, so returning one here would put
 * visitor-specific state into a shared cache. A consumer that wants the menu
 * itself asks for it as its own query against its own schema scope; that
 * surface belongs to the GraphQL phase, not to this field.
 *
 * Registered through {@see GqlEntityRegistry} so every field instance in the
 * schema shares one type definition — webonyx rejects two distinct types with
 * the same name, which is exactly what a per-field `new ObjectType()` would
 * produce on a site with two navigation fields. The registry applies the
 * install's configured type prefix; nothing here prefixes the name itself.
 */
class MenuBuilderMenuType
{
    public const NAME = 'MenuBuilderMenu';

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => Craft::t('menu-builder', 'A navigation selected by a MenuBuilder Navigation field.'),
            'fields' => self::fieldDefinitions(),
        ]));
    }

    /**
     * The type's fields, resolvers included, as a plain array.
     *
     * Kept separate from {@see getType()} — which needs a booted Craft
     * application to read the schema's type prefix — so that what each field
     * actually *returns* for a given value is unit-testable, the same
     * separation {@see \Tahadudhiya\MenuBuilder\helpers\MenuBuilderFieldHelper}
     * makes for the field's validation.
     *
     * Every resolver reads the value object's accessors rather than webonyx's
     * default property lookup: `handle`, `name`, `exists` and `enabled` are
     * all derived from the selected menu, and only `uid` is stored.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function fieldDefinitions(): array
    {
        return [
            'uid' => [
                'name' => 'uid',
                'type' => Type::string(),
                'description' => Craft::t('menu-builder', 'The selected navigation’s UID — stable across handle renames and environments.'),
                'resolve' => static fn(MenuBuilderFieldValue $value) => $value->groupUid,
            ],
            'handle' => [
                'name' => 'handle',
                'type' => Type::string(),
                'description' => Craft::t('menu-builder', 'The selected navigation’s handle, or null if it no longer exists.'),
                'resolve' => static fn(MenuBuilderFieldValue $value) => $value->getHandle(),
            ],
            'name' => [
                'name' => 'name',
                'type' => Type::string(),
                'description' => Craft::t('menu-builder', 'The selected navigation’s name, or null if it no longer exists.'),
                'resolve' => static fn(MenuBuilderFieldValue $value) => $value->getName(),
            ],
            'exists' => [
                'name' => 'exists',
                'type' => Type::nonNull(Type::boolean()),
                'description' => Craft::t('menu-builder', 'Whether the selected navigation still exists.'),
                'resolve' => static fn(MenuBuilderFieldValue $value) => $value->exists(),
            ],
            'enabled' => [
                'name' => 'enabled',
                'type' => Type::nonNull(Type::boolean()),
                'description' => Craft::t('menu-builder', 'Whether the selected navigation is enabled. A disabled navigation renders nothing.'),
                'resolve' => static fn(MenuBuilderFieldValue $value) => $value->isEnabled(),
            ],
        ];
    }
}

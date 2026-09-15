<?php

namespace Tahadudhiya\MenuBuilder\gql;

use Craft;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderGqlHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * A resolved menu: the menu's own public facts plus its already-filtered items.
*/
class MenuBuilderNavigationType
{
    public const NAME = 'MenuBuilderNavigation';

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => Craft::t('menubuilder', 'A resolved MenuBuilder navigation.'),
            'fields' => fn() => self::fieldDefinitions(),
        ]));
    }

    /** @return array<string,array<string,mixed>> */
    public static function fieldDefinitions(): array
    {
        return [
            'handle' => [
                'name' => 'handle',
                'type' => Type::nonNull(Type::string()),
                'description' => Craft::t('menubuilder', 'The menu’s handle.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->handle,
            ],
            'name' => [
                'name' => 'name',
                'type' => Type::nonNull(Type::string()),
                'description' => Craft::t('menubuilder', 'The menu’s name.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->name,
            ],
            'uid' => [
                'name' => 'uid',
                'type' => Type::string(),
                'description' => Craft::t('menubuilder', 'The menu’s UID — the same identifier a Navigation field stores, and the one a GraphQL schema is scoped by.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->uid,
            ],
            'description' => [
                'name' => 'description',
                'type' => Type::string(),
                'description' => Craft::t('menubuilder', 'The menu’s description.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->description,
            ],
            'cssClass' => [
                'name' => 'cssClass',
                'type' => Type::string(),
                'description' => Craft::t('menubuilder', 'The CSS class for the rendered navigation element.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->cssClass,
            ],
            'maxDepth' => [
                'name' => 'maxDepth',
                'type' => Type::int(),
                'description' => Craft::t('menubuilder', 'How deep this menu is allowed to nest, or null when it is unlimited.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->maxDepth,
            ],
            'htmlAttributes' => [
                'name' => 'htmlAttributes',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(MenuBuilderNavigationItemType::attributeType()))),
                'description' => Craft::t('menubuilder', 'The navigation element’s custom HTML attributes, already stripped of anything unsafe or reserved.'),
                'resolve' => static fn(MenuBuilderTree $tree) => MenuBuilderGqlHelper::attributePairs($tree->group->safeHtmlAttributes()),
            ],
            'itemCount' => [
                'name' => 'itemCount',
                'type' => Type::nonNull(Type::int()),
                'description' => Craft::t('menubuilder', 'How many top-level items this menu has, after filtering.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->count(),
            ],
            'items' => [
                'name' => 'items',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(MenuBuilderNavigationItemType::getType()))),
                'description' => Craft::t('menubuilder', 'The menu’s top-level items, already visibility-filtered and in order.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->items,
            ],
        ];
    }
}

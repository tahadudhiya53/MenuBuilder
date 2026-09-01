<?php

namespace Tahadudhiya\MenuBuilder\gql;

use Craft;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderGqlHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * A resolved menu: the menu's own public facts plus its already-filtered
 * items.
 *
 * Distinct from {@see MenuBuilderMenuType}, which is the Navigation *field's*
 * value — a selection, not a tree. That type stays exactly what it is; a
 * consumer that has a selection takes its `handle` and asks for the tree
 * here, where the site, the audience and the current page are stated as
 * arguments instead of being inherited from whichever entry happened to be
 * queried.
 *
 * The source value is a {@see MenuBuilderTree}, so what reaches this type has
 * already been through the whole resolve pipeline: enabled menus only,
 * site-gated, link-resolved, visibility-filtered and (when the query asked
 * for it) active-state marked. This type does no filtering of its own — it is
 * a projection, and everything security-relevant happened upstream in
 * {@see MenuBuilderNavigationResolver}.
 *
 * What is deliberately absent: the menu's row ID, its site restriction list,
 * and its `settings` bag. A restriction list is an install's structure rather
 * than a fact about the menu a visitor is being handed, and a menu that isn't
 * available on the requested site is simply not returned at all — a consumer
 * cannot learn from this type which *other* sites a menu exists on.
 */
class MenuBuilderNavigationType
{
    public const NAME = 'MenuBuilderNavigation';

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => Craft::t('menu-builder', 'A resolved MenuBuilder navigation.'),
            'fields' => fn() => self::fieldDefinitions(),
        ]));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function fieldDefinitions(): array
    {
        return [
            'handle' => [
                'name' => 'handle',
                'type' => Type::nonNull(Type::string()),
                'description' => Craft::t('menu-builder', 'The menu’s handle.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->handle,
            ],
            'name' => [
                'name' => 'name',
                'type' => Type::nonNull(Type::string()),
                'description' => Craft::t('menu-builder', 'The menu’s name.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->name,
            ],
            'uid' => [
                'name' => 'uid',
                'type' => Type::string(),
                'description' => Craft::t('menu-builder', 'The menu’s UID — the same identifier a Navigation field stores, and the one a GraphQL schema is scoped by.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->uid,
            ],
            'description' => [
                'name' => 'description',
                'type' => Type::string(),
                'description' => Craft::t('menu-builder', 'The menu’s description.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->description,
            ],
            'cssClass' => [
                'name' => 'cssClass',
                'type' => Type::string(),
                'description' => Craft::t('menu-builder', 'The CSS class for the rendered navigation element.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->cssClass,
            ],
            'maxDepth' => [
                'name' => 'maxDepth',
                'type' => Type::int(),
                'description' => Craft::t('menu-builder', 'How deep this menu is allowed to nest, or null when it is unlimited.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->group->maxDepth,
            ],
            'htmlAttributes' => [
                'name' => 'htmlAttributes',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(MenuBuilderNavigationItemType::attributeType()))),
                'description' => Craft::t('menu-builder', 'The navigation element’s custom HTML attributes, already stripped of anything unsafe or reserved.'),
                'resolve' => static fn(MenuBuilderTree $tree) => MenuBuilderGqlHelper::attributePairs($tree->group->safeHtmlAttributes()),
            ],
            'itemCount' => [
                'name' => 'itemCount',
                'type' => Type::nonNull(Type::int()),
                'description' => Craft::t('menu-builder', 'How many top-level items this menu has, after filtering.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->count(),
            ],
            'items' => [
                'name' => 'items',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(MenuBuilderNavigationItemType::getType()))),
                'description' => Craft::t('menu-builder', 'The menu’s top-level items, already visibility-filtered and in order.'),
                'resolve' => static fn(MenuBuilderTree $tree) => $tree->items,
            ],
        ];
    }
}

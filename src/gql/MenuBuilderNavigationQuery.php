<?php

namespace Tahadudhiya\MenuBuilder\gql;

use Craft;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderGqlHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Throwable;

/**
 * The plugin's two root query fields, and the schema components that gate them.
*/
class MenuBuilderNavigationQuery
{
    /**
     * @param bool $checkToken Whether to hide the fields from a schema that names no menu.
     * @return array<string,array<string,mixed>>
    */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !self::schemaNamesAnyMenu()) {
            return [];
        }

        return [
            'menuBuilder' => [
                'type' => MenuBuilderNavigationType::getType(),
                'args' => self::arguments(withHandle: true),
                'resolve' => MenuBuilderNavigationResolver::class . '::resolveOne',
                'description' => Craft::t('menu-builder', 'Query one MenuBuilder navigation by handle. Returns null when the menu doesn’t exist, is disabled, isn’t available on the requested site, or isn’t in this schema’s scope.'),
                'complexity' => GqlHelper::singleQueryComplexity(),
            ],
            'menuBuilderNavigations' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(MenuBuilderNavigationType::getType()))),
                'args' => self::arguments(),
                'resolve' => MenuBuilderNavigationResolver::class . '::resolveAll',
                'description' => Craft::t('menu-builder', 'Query every enabled MenuBuilder navigation this schema may read, on the requested site.'),
                'complexity' => GqlHelper::nPlus1Complexity(),
            ],
        ];
    }

    /**
     * The arguments both queries share.
     *
     * @return array<string,array<string,mixed>>
    */
    private static function arguments(bool $withHandle = false): array
    {
        $args = [];

        if ($withHandle) {
            $args['handle'] = [
                'name' => 'handle',
                'type' => Type::nonNull(Type::string()),
                'description' => Craft::t('menu-builder', 'The navigation’s handle.'),
            ];
        }

        return $args + [
            'site' => [
                'name' => 'site',
                'type' => Type::string(),
                'description' => Craft::t('menu-builder', 'The site to resolve for, by handle. Defaults to the request’s site. Must be a site this schema is allowed to query.'),
            ],
            'siteId' => [
                'name' => 'siteId',
                'type' => Type::int(),
                'description' => Craft::t('menu-builder', 'The site to resolve for, by ID. An alternative to `site`; giving both is only valid if they name the same site.'),
            ],
            'currentUri' => [
                'name' => 'currentUri',
                'type' => Type::string(),
                'description' => Craft::t('menu-builder', 'The URI of the page being rendered, so `isActive` and `isActiveAncestor` can be computed. Without it, both are false — a GraphQL request has no current page of its own.'),
            ],
            'viewport' => [
                'name' => 'viewport',
                'type' => Type::string(),
                'description' => Craft::t('menu-builder', 'Reshape the menu for one viewport — “desktop” or “mobile”: items restricted to the other one are removed, and mobile order is applied. Omit for the unshaped menu.'),
            ],
        ];
    }

    /**
     * The per-menu schema components, added to the schema editor under the plugin's own heading.
     *
     * @return array<string,array<string,mixed>>
    */
    public static function schemaComponents(): array
    {
        $components = [];

        foreach (self::allMenus() as $group) {
            $component = MenuBuilderGqlHelper::scopeComponent($group->uid);

            if ($component === null) {
                continue;
            }

            $components["$component:read"] = [
                'label' => Craft::t('menu-builder', 'View the “{menu}” navigation', ['menu' => $group->name]),
            ];
        }

        return $components;
    }

    /**
     * Whether the active schema names at least one menu.
    */
    private static function schemaNamesAnyMenu(): bool
    {
        foreach (self::allMenus() as $group) {
            if (MenuBuilderNavigationResolver::canRead($group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every menu, including disabled ones — a schema is long-lived configuration, and a menu that
     * is disabled today is still the menu the schema means.
     *
     * @return MenuBuilderGroup[]
    */
    private static function allMenus(): array
    {
        try {
            return MenuBuilder::getInstance()?->groups->getAll() ?? [];
        } catch (Throwable $e) {
            Craft::warning('Could not read MenuBuilder menus while building the GraphQL schema: ' . $e->getMessage(), __METHOD__);

            return [];
        }
    }
}

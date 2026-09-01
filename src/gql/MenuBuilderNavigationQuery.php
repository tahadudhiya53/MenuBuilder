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
 * The plugin's two root query fields, and the schema components that gate
 * them.
 *
 * ## GraphQL is opt-in, per menu
 *
 * The plugin registers no scope of its own anywhere: a fresh install, and an
 * install that upgrades into this version, exposes **nothing** over GraphQL
 * until somebody ticks a menu in a schema's settings. When the active schema
 * names no menu, {@see getQueries()} returns nothing at all, so the fields
 * are absent from that schema — introspection included. A headless site opts
 * in menu by menu; a site that doesn't use GraphQL is unaffected by this
 * phase existing.
 *
 * Mutations are deliberately not offered. Editing navigation is control-panel
 * work behind MenuBuilder's own permissions and CSRF protection (see
 * BaseMenuBuilderController); a GraphQL token is not a user and has no
 * MenuBuilder permissions to check, so a write surface here would have no
 * permission model to enforce beyond the token itself.
 */
class MenuBuilderNavigationQuery
{
    /**
     * @param bool $checkToken Whether to hide the fields from a schema that names no menu.
     *                         False when Craft is building the full schema for its own
     *                         purposes rather than for a token.
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
     * Every one of them is a *stated* fact rather than something inferred
     * from the request, because Craft's GraphQL result cache is keyed by the
     * query and its variables and by nothing else about the caller — see
     * {@see MenuBuilderNavigationResolver}. Anything this surface varied on
     * that wasn't an argument would be a shared cache entry that depended on
     * who filled it.
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
     * The per-menu schema components, added to the schema editor under the
     * plugin's own heading.
     *
     * Read only — there is no mutation surface to grant (see the class
     * docblock).
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
     *
     * Cheap by construction: the group service memoizes its list, and this
     * runs once per schema build rather than once per query.
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
     * Every menu, including disabled ones — a schema is long-lived
     * configuration, and a menu that is disabled today is still the menu the
     * schema means. Enabled state is enforced when the menu is *resolved*,
     * not when it is granted.
     *
     * Guarded against throwing: a schema is built in contexts where this
     * plugin's tables may not be readable yet — mid-install, mid-migration,
     * or with the plugin's own migration pending. A GraphQL schema that fails
     * to build takes down every query on the site, so a menu list that can't
     * be read means "no menus to offer", not a 500.
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

<?php

namespace Tahadudhiya\MenuBuilder\gql;

use GraphQL\Type\Definition\ResolveInfo;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;
use Tahadudhiya\MenuBuilder\services\MenuBuilderScopeService;

/**
 * The GraphQL half of the headless surface: webonyx's resolver signatures,
 * and nothing else.
 *
 * Every decision that matters — schema scope, existence, enabled state, site
 * availability, site scope, the anonymous audience, the resolve itself —
 * lives in {@see MenuBuilderScopeService}, which the REST API shares. This
 * class only adapts the shapes: webonyx hands resolvers four positional
 * arguments and wants a plain return value, so that is what it is for. When
 * the two transports' behaviour is compared, compare the service.
 *
 * ## Why every failure is the same `null`
 *
 * An unknown handle, a malformed handle, a disabled menu, a menu outside the
 * schema's scope and a menu unavailable on the requested site are
 * indistinguishable to the caller, and none of them is an *error*. A
 * GraphQL API that answered "that menu exists but you may not have it" would
 * be an enumeration oracle for an install's structure.
 *
 * ## Cache behaviour
 *
 * Craft's GraphQL result cache is keyed by (site, schema, query, variables)
 * and by nothing about the caller. Everything these resolvers vary on —
 * handle, site, `currentUri`, `viewport` — is an argument, and so is already
 * part of that key. That is the design constraint the service's constant
 * audience exists to satisfy: the response has to be a pure function of the
 * arguments, or it cannot be shared, and Craft will share it regardless.
 */
class MenuBuilderNavigationResolver
{
    /**
     * `menuBuilder(handle: "main")` — one menu, or null.
     *
     * @param array<string,mixed> $arguments
     */
    public static function resolveOne(mixed $source, array $arguments, mixed $context = null, ?ResolveInfo $resolveInfo = null): ?MenuBuilderTree
    {
        return self::scope()->resolveByHandle($arguments['handle'] ?? null, $arguments);
    }

    /**
     * `menuBuilderNavigations` — every menu this schema may read, on the
     * requested site, in the order the control panel lists them.
     *
     * @param array<string,mixed> $arguments
     * @return MenuBuilderTree[]
     */
    public static function resolveAll(mixed $source, array $arguments, mixed $context = null, ?ResolveInfo $resolveInfo = null): array
    {
        return self::scope()->resolveAll($arguments);
    }

    /**
     * The enabled menus the active schema is allowed to read.
     *
     * Kept here as well as on the service because
     * {@see MenuBuilderNavigationQuery} asks the question while *building* a
     * schema, which is a GraphQL concern.
     *
     * @return MenuBuilderGroup[]
     */
    public static function readableMenus(): array
    {
        return self::scope()->readableMenus();
    }

    /** Whether the active schema names this menu. */
    public static function canRead(MenuBuilderGroup $group): bool
    {
        return self::scope()->canRead($group);
    }

    private static function scope(): MenuBuilderScopeService
    {
        return MenuBuilder::getInstance()->scope;
    }
}

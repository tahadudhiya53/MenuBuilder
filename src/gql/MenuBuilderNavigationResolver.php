<?php

namespace Tahadudhiya\MenuBuilder\gql;

use GraphQL\Type\Definition\ResolveInfo;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;
use Tahadudhiya\MenuBuilder\services\MenuBuilderScopeService;

/**
 * The GraphQL half of the headless surface: webonyx's resolver signatures, and nothing else.
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
     * `menuBuilderNavigations` — every menu this schema may read, on the requested site, in the
     * order the control panel lists them.
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
     * @return MenuBuilderGroup[]
    */
    public static function readableMenus(): array
    {
        return self::scope()->readableMenus();
    }

    /**
     * Whether the active schema names this menu.
    */
    public static function canRead(MenuBuilderGroup $group): bool
    {
        return self::scope()->canRead($group);
    }

    private static function scope(): MenuBuilderScopeService
    {
        return MenuBuilder::getInstance()->scope;
    }
}

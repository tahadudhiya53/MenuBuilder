<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\helpers\Gql as GqlHelper;
use craft\models\Site;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderGqlHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * The gates a **headless** caller passes, and the resolve behind them — shared by GraphQL ({@see
 * \Tahadudhiya\MenuBuilder\gql\MenuBuilderNavigationResolver}) and by the REST API ({@see
 * \Tahadudhiya\MenuBuilder\controllers\ApiController}).
*/
class MenuBuilderScopeService extends Component
{
    /**
     * The enabled menus the active schema is allowed to read, in the order the control panel lists
     * them.
     *
     * @return MenuBuilderGroup[]
    */
    public function readableMenus(): array
    {
        $groups = MenuBuilder::getInstance()->groups->getAll(includeDisabled: false);

        return array_values(array_filter($groups, fn(MenuBuilderGroup $group) => $this->canRead($group)));
    }

    /**
     * Whether the active schema names this menu.
    */
    public function canRead(MenuBuilderGroup $group): bool
    {
        $component = MenuBuilderGqlHelper::scopeComponent($group->uid);

        return $component !== null && GqlHelper::canSchema($component);
    }

    /**
     * Handle → tree, through every gate.
     *
     * @param array<string,mixed> $arguments `site`, `siteId`, `currentUri`, `viewport`.
    */
    public function resolveByHandle(mixed $handle, array $arguments = []): ?MenuBuilderTree
    {
        $handle = MenuBuilderGqlHelper::normalizeHandle($handle);

        if ($handle === null) {
            return null;
        }

        $group = MenuBuilder::getInstance()->groups->getByHandle($handle);

        if ($group === null || !$group->enabled || !$this->canRead($group)) {
            return null;
        }

        return $this->resolveTree($group, $arguments);
    }

    /**
     * Every menu this schema may read, resolved on the requested site.
     *
     * @param array<string,mixed> $arguments
     * @return MenuBuilderTree[]
    */
    public function resolveAll(array $arguments = []): array
    {
        $trees = [];

        foreach ($this->readableMenus() as $group) {
            $tree = $this->resolveTree($group, $arguments);

            if ($tree !== null) {
                $trees[] = $tree;
            }
        }

        return $trees;
    }

    /**
     * The resolve pipeline, run on the requested site for the anonymous audience.
     *
     * @param array<string,mixed> $arguments
    */
    public function resolveTree(MenuBuilderGroup $group, array $arguments = []): ?MenuBuilderTree
    {
        $sites = Craft::$app->getSites();
        $requested = $this->requestedSite($arguments);

        // A site argument that named nothing this schema may query.
        if ($requested === false) {
            return null;
        }

        $original = $sites->getCurrentSite();
        $site = $requested ?? $original;

        // The site has to be switched, not merely passed down: the resolve pipeline reads the
        // *current* site in two places this call can't reach — MenuBuilderCacheService keys
        // entries by it, and ElementLinkResolver resolves element URLs against it.
        if ($site->id !== $original->id) {
            $sites->setCurrentSite($site);
        }

        // Active state is only ever computed against a URI the caller named.
        $currentUri = MenuBuilderGqlHelper::normalizeCurrentUri($arguments['currentUri'] ?? null);

        try {
            $timezone = new \DateTimeZone(Craft::$app->getTimeZone());

            $tree = MenuBuilder::getInstance()->resolver->getTree(
                $group->handle,
                currentUri: $currentUri,
                context: MenuBuilderGqlHelper::anonymousContext((int)$site->id, $timezone, Craft::$app->env),
                markActive: $currentUri !== null,
            );
        } finally {
            if ($site->id !== $original->id) {
                $sites->setCurrentSite($original);
            }
        }

        if ($tree === null) {
            return null;
        }

        $viewport = MenuBuilderGqlHelper::normalizeViewport($arguments['viewport'] ?? null);

        return $viewport === null ? $tree : $tree->forViewport($viewport);
    }

    /**
     * The site the caller asked for: a `Site` when one was named and allowed, `null` when none was
     * named (use the request's), or `false` when one was named that this schema may not query,
     * doesn't exist, or was given twice and disagreed with itself.
     *
     * @param array<string,mixed> $arguments
    */
    public function requestedSite(array $arguments): Site|false|null
    {
        $handle = MenuBuilderGqlHelper::normalizeHandle($arguments['site'] ?? null);
        $id = MenuBuilderGqlHelper::normalizeSiteId($arguments['siteId'] ?? null);

        // Given but unusable — a non-handle string, a zero or negative ID.
        if ($handle === null && isset($arguments['site']) && $arguments['site'] !== null) {
            return false;
        }

        if ($id === null && isset($arguments['siteId']) && $arguments['siteId'] !== null) {
            return false;
        }

        if ($handle === null && $id === null) {
            return null;
        }

        $sites = Craft::$app->getSites();
        $byHandle = $handle !== null ? $sites->getSiteByHandle($handle) : null;
        $byId = $id !== null ? $sites->getSiteById($id) : null;

        if (($handle !== null && $byHandle === null) || ($id !== null && $byId === null)) {
            return false;
        }

        // Both given: they must be the same site.
        if ($byHandle !== null && $byId !== null && $byHandle->id !== $byId->id) {
            return false;
        }

        $site = $byHandle ?? $byId;

        // The same boundary Craft's own `site` argument enforces (see craft\gql\handlers\Site): a
        // schema can only query the sites it has been granted.
        $allowedIds = array_map(static fn(Site $allowed) => (int)$allowed->id, GqlHelper::getAllowedSites());

        return in_array((int)$site->id, $allowedIds, true) ? $site : false;
    }
}

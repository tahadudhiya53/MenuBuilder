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
 * The gates a **headless** caller passes, and the resolve behind them —
 * shared by GraphQL ({@see \Tahadudhiya\MenuBuilder\gql\MenuBuilderNavigationResolver})
 * and by the REST API ({@see \Tahadudhiya\MenuBuilder\controllers\ApiController}).
 *
 * One implementation, because these are the security-relevant decisions of
 * both transports and two copies of them is two places for an authorization
 * hole to hide (ARCHITECTURE.md, "Single path per behaviour"). The
 * transports differ in how they *report* a refusal — GraphQL returns null,
 * REST returns a status code — and in nothing else.
 *
 * ## The five gates
 *
 * 1. **Schema scope.** The active schema must name the menu:
 *    `menuBuilderGroups.{uid}:read`. Nothing is granted by default, so the
 *    surface is opt-in per menu and per token, for both transports.
 * 2. **Existence and enabled state**, and 3. **site availability** — both
 *    enforced by {@see MenuBuilderResolver::getTree()}, which is the single
 *    entry point for resolving a menu and is not bypassed here. A disabled
 *    menu, or one restricted to other sites, resolves to null.
 * 4. **Site scope.** An explicitly requested site must be one the schema is
 *    allowed to query ({@see GqlHelper::getAllowedSites()}) — the same
 *    boundary Craft's own element queries observe.
 * 5. **Visibility.** Per-item rules are evaluated against the anonymous
 *    audience — see {@see MenuBuilderGqlHelper::anonymousContext()} for why
 *    that is a correctness requirement and not a simplification.
 *
 * ## Why the audience is nobody, on both transports
 *
 * A headless response is cached and shared — Craft's GraphQL result cache by
 * (site, schema, query, variables), an HTTP cache by URL and `Vary` — and by
 * nothing about the caller. A tree resolved for whoever happened to send the
 * request would therefore be handed back to every later caller sharing that
 * key. Making the audience a constant is what makes the response a pure
 * function of its arguments, which is what makes it cacheable at all. Items
 * restricted to logged-in users or to a user group are absent from a
 * headless response; items restricted to logged-out visitors are present.
 *
 * The plugin's own tree cache is read through the normal pipeline and is
 * given nothing caller-specific either: viewport reshaping and active
 * marking both happen after the cache read, on copies.
 */
class MenuBuilderScopeService extends Component
{
    /**
     * The enabled menus the active schema is allowed to read, in the order
     * the control panel lists them.
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
     *
     * An unsaved menu has no UID to be named by, and so is never readable —
     * the same fail-closed reading {@see MenuBuilderGqlHelper::scopeComponent()}
     * takes.
     */
    public function canRead(MenuBuilderGroup $group): bool
    {
        $component = MenuBuilderGqlHelper::scopeComponent($group->uid);

        return $component !== null && GqlHelper::canSchema($component);
    }

    /**
     * Handle → tree, through every gate. Null for a handle that isn't one, a
     * menu that doesn't exist, is disabled, is outside the schema's scope,
     * or isn't available on the requested site.
     *
     * All of those are the *same* null on purpose. A headless API that
     * answered "that menu exists but you may not have it" would be an
     * enumeration oracle for an install's structure, so the caller cannot
     * tell an unknown handle from a forbidden one.
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
     * Menus the schema doesn't name are absent rather than null-padded, so
     * the list's length says nothing about how many menus the install has.
     * Null here is a menu that is real and in scope but not available on
     * this site — it drops out of the list exactly as it would drop out of a
     * page's rendering.
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
     * The resolve pipeline, run on the requested site for the anonymous
     * audience.
     *
     * @param array<string,mixed> $arguments
     */
    public function resolveTree(MenuBuilderGroup $group, array $arguments = []): ?MenuBuilderTree
    {
        $sites = Craft::$app->getSites();
        $requested = $this->requestedSite($arguments);

        // A site argument that named nothing this schema may query. Not an
        // error, and not a silent fall back to the current site either: the
        // caller asked for a specific site's navigation, and giving them a
        // different site's would be a worse answer than none.
        if ($requested === false) {
            return null;
        }

        $original = $sites->getCurrentSite();
        $site = $requested ?? $original;

        // The site has to be switched, not merely passed down: the resolve
        // pipeline reads the *current* site in two places this call can't
        // reach — MenuBuilderCacheService keys entries by it, and
        // ElementLinkResolver resolves element URLs against it. Passing a
        // site ID into the visibility context alone would filter for one site
        // while resolving URLs for another.
        if ($site->id !== $original->id) {
            $sites->setCurrentSite($site);
        }

        // Active state is only ever computed against a URI the caller named.
        // A headless request's own URI is the API endpoint, not the page
        // whose navigation this is, so marking against it would be both
        // meaningless and — since it is not an argument — outside the
        // response cache's key.
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
     * The site the caller asked for: a `Site` when one was named and
     * allowed, `null` when none was named (use the request's), or `false`
     * when one was named that this schema may not query, doesn't exist, or
     * was given twice and disagreed with itself.
     *
     * @param array<string,mixed> $arguments
     */
    public function requestedSite(array $arguments): Site|false|null
    {
        $handle = MenuBuilderGqlHelper::normalizeHandle($arguments['site'] ?? null);
        $id = MenuBuilderGqlHelper::normalizeSiteId($arguments['siteId'] ?? null);

        // Given but unusable — a non-handle string, a zero or negative ID.
        // Rejected rather than ignored, so a typo can't quietly return the
        // current site's navigation as if it were the one asked for.
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

        // Both given: they must be the same site. Picking one and ignoring
        // the other would answer a question the caller didn't ask.
        if ($byHandle !== null && $byId !== null && $byHandle->id !== $byId->id) {
            return false;
        }

        $site = $byHandle ?? $byId;

        // The same boundary Craft's own `site` argument enforces (see
        // craft\gql\handlers\Site): a schema can only query the sites it
        // has been granted.
        $allowedIds = array_map(static fn(Site $allowed) => (int)$allowed->id, GqlHelper::getAllowedSites());

        return in_array((int)$site->id, $allowedIds, true) ? $site : false;
    }
}

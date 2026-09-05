<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use stdClass;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderApiConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * The decidable half of the REST API: query-parameter validation, the JSON
 * shapes, the error envelope, and the rate limiter's arithmetic.
 *
 * Separated from {@see \Tahadudhiya\MenuBuilder\controllers\ApiController}
 * for the same reason {@see MenuBuilderGqlHelper} is separated from the
 * GraphQL types — everything here is decidable without a booted Craft
 * application, so what the API actually *returns* for a given tree, and what
 * it refuses, is unit-testable rather than only reachable through a real
 * HTTP request.
 *
 * ## What is exposed, and what is not
 *
 * The item shape mirrors {@see \Tahadudhiya\MenuBuilder\gql\MenuBuilderNavigationItemType}
 * field for field, deliberately: two transports over one menu must not
 * disagree about what a menu *is*. In particular:
 *
 * - **No row IDs.** A node carries `id` for Twig's benefit; it is a
 *   `menubuilder_items` primary key, an internal fact about this install's
 *   database. `handle` is the editor-set, stable, intentionally public name
 *   for an item, and it is what this exposes. (A dynamic item's synthesized
 *   children carry a Craft *element* ID in the same property, so an `id`
 *   here would mean two different things depending on the node.)
 * - **Nothing an editor configured but a visitor never sees** — visibility
 *   rules, fallback behaviour, sort columns, the raw metadata bag, the
 *   menu's site-restriction list. A visitor cannot see them in HTML and an
 *   API consumer is a visitor.
 * - **No database records.** Every value is read through the node's own
 *   accessors, so the fail-closed reads those perform
 *   ({@see MenuBuilderNode::safeHtmlAttributes()},
 *   {@see MenuBuilderNode::iconClass()}, {@see MenuBuilderNode::badgeClass()})
 *   apply to a JSON response exactly as they apply to a rendered one.
 *
 * Where JSON has a better representation than GraphQL's, it is used rather
 * than transcribed: `htmlAttributes` and `customFields` are **objects**,
 * not lists of `{name, value}` pairs, and a custom field's boolean stays a
 * JSON boolean instead of needing four typed accessors. Related fields are
 * grouped (`icon`, `badge`, `mobile`, `megaMenu`) because a consumer either
 * wants all of a group or none of it.
 */
class MenuBuilderApiHelper
{
    /** The query parameters both endpoints accept. Anything else is ignored. */
    public const PARAMS = ['site', 'siteId', 'currentUri', 'viewport'];

    /** Error codes, one per status the API can answer with. */
    public const ERROR_BAD_REQUEST = 'bad_request';
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_FORBIDDEN = 'forbidden';
    public const ERROR_NOT_FOUND = 'not_found';
    public const ERROR_METHOD_NOT_ALLOWED = 'method_not_allowed';
    public const ERROR_RATE_LIMITED = 'rate_limited';

    /** The rate limiter's fixed window, in seconds. */
    public const RATE_WINDOW = 60;

    // -----------------------------------------------------------------
    // Input
    // -----------------------------------------------------------------

    /**
     * The name of the first query parameter that was given but isn't valid,
     * or null when everything present is usable.
     *
     * Deliberately stricter than the GraphQL surface, which folds "absent"
     * and "malformed" into the same "don't reshape"/"use the current site".
     * A GraphQL argument is typed by the schema before a resolver ever sees
     * it; a query string is not, so `?viewport=mobil` has to be answerable
     * as the typo it is. The normalizers themselves are shared with GraphQL
     * ({@see MenuBuilderGqlHelper}) — only the presence check is added here,
     * so the two transports cannot drift about what *counts* as a handle, a
     * site ID, a URI or a viewport.
     *
     * A parameter present but empty (`?site=`) is a malformed parameter, not
     * an absent one: the caller wrote it, and silently ignoring it would
     * answer for a different site than the one they meant to name.
     *
     * @param array<string,mixed> $params
     */
    public static function invalidParam(array $params): ?string
    {
        $checks = [
            'site' => static fn(mixed $v) => MenuBuilderGqlHelper::normalizeHandle($v) !== null,
            'siteId' => static fn(mixed $v) => MenuBuilderGqlHelper::normalizeSiteId($v) !== null,
            'currentUri' => static fn(mixed $v) => MenuBuilderGqlHelper::normalizeCurrentUri($v) !== null,
            'viewport' => static fn(mixed $v) => MenuBuilderGqlHelper::normalizeViewport($v) !== null,
        ];

        foreach ($checks as $param => $isValid) {
            if (array_key_exists($param, $params) && !$isValid($params[$param])) {
                return $param;
            }
        }

        return null;
    }

    /**
     * The subset of a query string the resolve pipeline understands, with
     * every value already normalized.
     *
     * Built by allowlist rather than by passing the query string through:
     * `$arguments` reaches
     * {@see \Tahadudhiya\MenuBuilder\services\MenuBuilderScopeService::resolveTree()},
     * which asks `isset($arguments['site'])` to tell "named nothing usable"
     * from "named nothing" — so an unrecognized parameter must not be able
     * to arrive there at all.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function arguments(array $params): array
    {
        $arguments = [];

        foreach (self::PARAMS as $param) {
            if (!array_key_exists($param, $params)) {
                continue;
            }

            $arguments[$param] = match ($param) {
                'site' => MenuBuilderGqlHelper::normalizeHandle($params[$param]),
                'siteId' => MenuBuilderGqlHelper::normalizeSiteId($params[$param]),
                'currentUri' => MenuBuilderGqlHelper::normalizeCurrentUri($params[$param]),
                'viewport' => MenuBuilderGqlHelper::normalizeViewport($params[$param]),
            };
        }

        return $arguments;
    }

    // -----------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------

    /**
     * The envelope every successful response uses: `meta` (facts about the
     * request that was answered) and `data` (the menu, or the list of them).
     *
     * `meta` echoes the resolved site and the reshaping arguments rather
     * than restating the request's query string, so a consumer can tell
     * which site actually answered without parsing its own URL — and can see
     * that `currentUri` was honoured, since active state is silently all-false
     * without it.
     *
     * @param array<string,mixed> $site
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function envelope(mixed $data, array $site, array $arguments): array
    {
        return [
            'meta' => [
                'apiVersion' => MenuBuilderApiConfig::RELEASE,
                'site' => $site,
                'currentUri' => $arguments['currentUri'] ?? null,
                'viewport' => $arguments['viewport'] ?? null,
            ],
            'data' => $data,
        ];
    }

    /**
     * The error envelope. `message` is for a developer reading a log; `code`
     * is the stable thing to branch on.
     *
     * The messages are deliberately incurious: "not found" is the answer for
     * an unknown menu, a disabled menu, a menu this schema may not read and
     * a menu unavailable on the requested site alike — see
     * {@see \Tahadudhiya\MenuBuilder\services\MenuBuilderScopeService::resolveByHandle()}
     * for why the caller must not be able to tell those apart.
     *
     * @return array<string,mixed>
     */
    public static function error(int $status, string $code, string $message): array
    {
        return [
            'error' => [
                'status' => $status,
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * A resolved menu, as JSON.
     *
     * @return array<string,mixed>
     */
    public static function serializeTree(MenuBuilderTree $tree): array
    {
        return [
            'handle' => $tree->group->handle,
            'name' => $tree->group->name,
            'uid' => $tree->group->uid,
            'description' => $tree->group->description,
            'cssClass' => $tree->group->cssClass,
            'maxDepth' => $tree->group->maxDepth,
            'htmlAttributes' => self::bag($tree->group->safeHtmlAttributes()),
            'itemCount' => $tree->count(),
            'items' => self::serializeNodes($tree->items),
        ];
    }

    /**
     * @param MenuBuilderNode[] $nodes
     * @return list<array<string,mixed>>
     */
    public static function serializeNodes(array $nodes): array
    {
        return array_values(array_map(self::serializeNode(...), $nodes));
    }

    /**
     * One resolved item, as JSON — recursive on `children`.
     *
     * @return array<string,mixed>
     */
    public static function serializeNode(MenuBuilderNode $node): array
    {
        return [
            // --- identity ---------------------------------------------
            'handle' => $node->handle,
            'type' => $node->type,
            'level' => $node->level,
            'isDynamic' => $node->isDynamic,

            // --- the link ---------------------------------------------
            'title' => $node->title,
            'url' => $node->url,
            'isClickable' => $node->isClickable,
            'isLinkAvailable' => $node->isLinkAvailable,
            'target' => $node->target,
            'rel' => $node->rel,
            'opensInNewTab' => $node->opensInNewTab(),

            // --- active state -----------------------------------------
            'isActive' => $node->isActive,
            'isActiveAncestor' => $node->isActiveAncestor,

            // --- presentation -----------------------------------------
            'cssClass' => $node->cssClass,
            'htmlId' => $node->htmlId,
            'htmlAttributes' => self::bag($node->safeHtmlAttributes()),
            'ariaLabel' => $node->ariaLabel,
            'titleAttribute' => $node->titleAttribute,
            'description' => $node->description,
            'featured' => $node->featured,
            // An asset ID, not a URL: an asset's URL can change without the
            // menu changing, so resolving one here would be a value the
            // menu's own cache has no reason to invalidate. Feed it back
            // into Craft's own asset query.
            'imageId' => $node->image,

            'icon' => self::icon($node),
            'badge' => self::badge($node),
            'megaMenu' => $node->megaMenu !== null ? ['columns' => $node->megaMenu->columns] : null,
            'megaMenuColumn' => $node->megaMenuColumn,
            'mobile' => self::mobile($node),

            // --- custom fields ----------------------------------------
            // The menu's Craft field layout, in each field's own serialized
            // form — a relation field is a list of element IDs, not resolved
            // elements, for the same reason `imageId` above is an ID: an
            // element can change without the menu changing, so resolving one
            // here would be a value this menu's cache has no reason to
            // invalidate. Feed the IDs back into Craft's own queries.
            'customFields' => self::bag(self::customFields($node)),

            // --- hierarchy --------------------------------------------
            'hasChildren' => $node->hasChildren(),
            'children' => self::serializeNodes($node->children),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function icon(MenuBuilderNode $node): ?array
    {
        if (!$node->hasIcon()) {
            return null;
        }

        return [
            'type' => $node->iconType(),
            'class' => $node->iconClass(),
            'assetId' => $node->iconAssetId(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function badge(MenuBuilderNode $node): ?array
    {
        if (!$node->hasBadge()) {
            return null;
        }

        return [
            'text' => $node->badge,
            'style' => $node->badgeStyle,
            'class' => $node->badgeClass(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function mobile(MenuBuilderNode $node): array
    {
        return [
            'visibility' => $node->mobileVisibility(),
            'order' => $node->mobileOrder(),
            'isCollapsible' => $node->isMobileCollapsible(),
            'megaMenuBehavior' => $node->mobileMegaMenuBehavior(),
            'viewportAttribute' => $node->viewportAttribute(),
        ];
    }

    /**
     * An open-ended bag as a JSON **object**.
     *
     * The cast matters: `json_encode([])` is `[]`, so an item with no custom
     * fields would encode its bag as an array while an item with one encodes
     * an object — a type that changes with the data, which is exactly what
     * breaks a typed consumer. An `stdClass` always encodes as `{}`.
     *
     * @param array<string,mixed> $values
     */
    public static function bag(array $values): stdClass
    {
        return (object)$values;
    }

    // -----------------------------------------------------------------
    // Caching and rate limiting
    // -----------------------------------------------------------------

    /**
     * The `ETag` for a rendered body.
     *
     * Weak-validator syntax is deliberately not used: the body is byte-exact
     * and a consumer that gets a 304 has exactly the bytes it cached.
     */
    public static function etag(string $body): string
    {
        return '"' . hash('xxh128', $body) . '"';
    }

    /**
     * Whether an `If-None-Match` header matches the entity tag we would send.
     *
     * Handles the list form (`"a", "b"`) and the weak prefix a proxy may
     * have added, because both are legal in a request and a 200 where a 304
     * was warranted is only wasted bandwidth — but a *false* match would
     * serve a stale menu, so matching is exact on the tag's opaque part.
     */
    public static function etagMatches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `Cache-Control` value for a successful response.
     *
     * `private` when a bearer token answered it, `public` when the public
     * schema did. The distinction is the whole point: a token's schema can
     * name menus the public schema cannot, so a shared cache that stored one
     * token's response under a URL would serve it to the next anonymous
     * caller. `Vary: Authorization` says the same thing, but only to caches
     * that honour it — `private` says it to all of them.
     *
     * A duration of 0 is `no-store` rather than `max-age=0`: an install that
     * didn't ask for HTTP caching gets none, and `ETag` still makes a repeat
     * request cheap.
     */
    public static function cacheControl(int $duration, bool $authenticated): string
    {
        if ($duration <= 0) {
            return 'no-store';
        }

        return ($authenticated ? 'private' : 'public') . ', max-age=' . $duration;
    }

    /**
     * The rate limiter's cache key for one caller in one window.
     *
     * The caller is identified by the token that authorized them plus the
     * address they came from, hashed: an access token must never be written
     * into a cache key that other code can enumerate, and the IP is personal
     * data that has no business being stored in the clear for a rate count.
     * A token is included at all so that one noisy anonymous network cannot
     * spend an authenticated integration's budget.
     */
    public static function rateLimitKey(?string $tokenUid, ?string $ip, int $window): string
    {
        return 'menu-builder:api:rate:' . hash('xxh128', ($tokenUid ?? 'public') . '|' . ($ip ?? 'unknown')) . ':' . $window;
    }

    /** The fixed window a timestamp falls in. */
    public static function rateLimitWindow(int $timestamp): int
    {
        return intdiv($timestamp, self::RATE_WINDOW);
    }

    /** How many seconds until the window a timestamp falls in ends. */
    public static function rateLimitResetsIn(int $timestamp): int
    {
        return self::RATE_WINDOW - ($timestamp % self::RATE_WINDOW);
    }

    /**
     * A node's custom field values in their serialized form.
     *
     * Short-circuited on a node with no content for the same reason
     * {@see MenuBuilderNode::custom()} is: nothing to look up, and no plugin
     * instance needed to say so.
     *
     * @return array<string,mixed>
     */
    private static function customFields(MenuBuilderNode $node): array
    {
        return $node->contentId === null
            ? []
            : MenuBuilder::getInstance()->itemContent->serializedValuesFor($node->contentId);
    }
}

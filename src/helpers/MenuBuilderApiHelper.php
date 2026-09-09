<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use stdClass;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderApiConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * The decidable half of the REST API: query-parameter validation, the JSON shapes, the error
 * envelope, and the rate limiter's arithmetic.
*/
class MenuBuilderApiHelper
{
    /**
     * The query parameters both endpoints accept.
    */
    public const PARAMS = ['site', 'siteId', 'currentUri', 'viewport'];

    /**
     * Error codes, one per status the API can answer with.
    */
    public const ERROR_BAD_REQUEST = 'bad_request';
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_FORBIDDEN = 'forbidden';
    public const ERROR_NOT_FOUND = 'not_found';
    public const ERROR_METHOD_NOT_ALLOWED = 'method_not_allowed';
    public const ERROR_RATE_LIMITED = 'rate_limited';

    /**
     * The rate limiter's fixed window, in seconds.
    */
    public const RATE_WINDOW = 60;

    // Input

    /**
     * The name of the first query parameter that was given but isn't valid, or null when everything
     * present is usable.
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
     * The subset of a query string the resolve pipeline understands, with every value already
     * normalized.
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

    // Output

    /**
     * The envelope every successful response uses: `meta` (facts about the request that was
     * answered) and `data` (the menu, or the list of them).
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
     * The error envelope.
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
            // An asset ID, not a URL: an asset's URL can change without the menu changing, so
            // resolving one here would be a value the menu's own cache has no reason to invalidate.
            'imageId' => $node->image,

            'icon' => self::icon($node),
            'badge' => self::badge($node),
            'megaMenu' => $node->megaMenu !== null ? ['columns' => $node->megaMenu->columns] : null,
            'megaMenuColumn' => $node->megaMenuColumn,
            'mobile' => self::mobile($node),

            // --- custom fields ---------------------------------------- The menu's Craft field
            // layout, in each field's own serialized form — a relation field is a list of element
            // IDs, not resolved elements, for the same reason `imageId` above is an ID: an element
            // can change without the menu changing, so resolving one here would be a value this
            // menu's cache has no reason to invalidate.
            'customFields' => self::bag(self::customFields($node)),

            // --- hierarchy --------------------------------------------
            'hasChildren' => $node->hasChildren(),
            'children' => self::serializeNodes($node->children),
        ];
    }

    /** @return array<string,mixed>|null */
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

    /** @return array<string,mixed>|null */
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

    /** @return array<string,mixed> */
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
     * @param array<string,mixed> $values
    */
    public static function bag(array $values): stdClass
    {
        return (object)$values;
    }

    // Caching and rate limiting

    /**
     * The `ETag` for a rendered body.
    */
    public static function etag(string $body): string
    {
        return '"' . hash('xxh128', $body) . '"';
    }

    /**
     * Whether an `If-None-Match` header matches the entity tag we would send.
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
    */
    public static function rateLimitKey(?string $tokenUid, ?string $ip, int $window): string
    {
        return 'menu-builder:api:rate:' . hash('xxh128', ($tokenUid ?? 'public') . '|' . ($ip ?? 'unknown')) . ':' . $window;
    }

    /**
     * The fixed window a timestamp falls in.
    */
    public static function rateLimitWindow(int $timestamp): int
    {
        return intdiv($timestamp, self::RATE_WINDOW);
    }

    /**
     * How many seconds until the window a timestamp falls in ends.
    */
    public static function rateLimitResetsIn(int $timestamp): int
    {
        return self::RATE_WINDOW - ($timestamp % self::RATE_WINDOW);
    }

    /**
     * A node's custom field values in their serialized form.
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

<?php

namespace Tahadudhiya\MenuBuilder\models;

/**
 * The read-only REST API's configuration, as read from `config/menu-builder.php`.
 *
 * ## Why there is a switch at all
 *
 * The API is **off unless an install turns it on**. Menus are already
 * opt-in per menu through the GraphQL schema scope
 * ({@see \Tahadudhiya\MenuBuilder\helpers\MenuBuilderGqlHelper::SCHEMA_COMPONENT_PREFIX}),
 * and the REST surface reuses that scope rather than inventing a second
 * permission model — but reusing it is exactly why a master switch is
 * needed: an install that ticked a menu into the *public* GraphQL schema
 * chose to expose it over GraphQL, at Craft's `/api` endpoint, and did not
 * thereby ask for a second unauthenticated HTTP endpoint to appear on its
 * site the day it upgraded. So the scope decides *which* menus the API can
 * ever serve, and this decides whether the API exists.
 *
 * When it is off, no URL rule is registered at all
 * ({@see \Tahadudhiya\MenuBuilder\MenuBuilder::attachEventHandlers()}) and
 * the controller refuses regardless — the route's absence is the primary
 * gate, the controller's check is defence in depth.
 *
 * Everything here is normalized in one pure static
 * ({@see fromArray()}) that never throws and never trusts the file: a config
 * file is edited by hand and deployed, and a typo in it must fail closed to
 * the default rather than 500 every request on the site.
 *
 * ```php
 * // config/menu-builder.php
 * return [
 *     'api' => [
 *         'enabled' => true,
 *         'basePath' => 'api/menu-builder',
 *         'allowPublicSchema' => true,
 *         'rateLimit' => 60,
 *         'cacheDuration' => 300,
 *         'allowedOrigins' => ['https://www.example.com'],
 *     ],
 * ];
 * ```
 */
final class MenuBuilderApiConfig
{
    /**
     * The API's **major** version, and the `/v1` segment of every route.
     *
     * A breaking change to the response shape — a field removed, a field's
     * type changed, a gate loosened — takes a new major and a new URL, so a
     * deployed consumer keeps talking to the version it was written against.
     * Additive changes (a new field) do not.
     */
    public const VERSION = 1;

    /**
     * The precise version, echoed as `meta.apiVersion` and in the
     * `X-MenuBuilder-Api-Version` header. The minor half moves on additive
     * changes, so a consumer can feature-detect without guessing.
     */
    public const RELEASE = '1.0';

    public const DEFAULT_BASE_PATH = 'api/menu-builder';

    /** Requests per minute, per caller. */
    public const DEFAULT_RATE_LIMIT = 60;

    /**
     * A base path is a URI path, and this is the grammar of one that can
     * safely be concatenated into a Yii URL rule: unreserved characters in
     * one or more `/`-separated segments, no empty segment, no dot segment.
     * Anything else falls back to the default rather than being escaped into
     * something the author didn't write.
     */
    private const BASE_PATH_PATTERN = '#^[A-Za-z0-9_~-][A-Za-z0-9._~-]*(/[A-Za-z0-9_~-][A-Za-z0-9._~-]*)*$#';

    /** `scheme://host[:port]`, and nothing after the authority — what the Origin header actually is. */
    private const ORIGIN_PATTERN = '#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$#';

    private function __construct(
        public readonly bool $enabled,
        public readonly string $basePath,
        /** Whether a request with no `Authorization` header may fall back to Craft's public schema. */
        public readonly bool $allowPublicSchema,
        /** Requests per minute per caller; 0 disables the limiter. */
        public readonly int $rateLimit,
        /** `max-age` for a successful response; 0 means `no-store`. */
        public readonly int $cacheDuration,
        /** @var string[] Exact origins, or the single entry `*`. Empty means no CORS headers at all. */
        public readonly array $allowedOrigins,
    ) {
    }

    /** The defaults, which are also what any malformed config collapses to. */
    public static function disabled(): self
    {
        return new self(
            enabled: false,
            basePath: self::DEFAULT_BASE_PATH,
            allowPublicSchema: true,
            rateLimit: self::DEFAULT_RATE_LIMIT,
            cacheDuration: 0,
            allowedOrigins: [],
        );
    }

    /**
     * Reads the `api` key of a `config/menu-builder.php` array.
     *
     * Every value is checked rather than cast: `'yes'` is not `true`, `-1`
     * is not a rate limit, and `'*'` is only a wildcard origin when it is
     * the *whole* entry. A value that isn't what it claims to be is replaced
     * by the default for that key — not by "permissive" — so the worst a
     * typo can do is leave the API in the state it shipped in.
     *
     * @param mixed $config The whole config file's array, or anything at all.
     */
    public static function fromArray(mixed $config): self
    {
        if (!is_array($config)) {
            return self::disabled();
        }

        $api = $config['api'] ?? null;

        if (!is_array($api)) {
            return self::disabled();
        }

        // `enabled` is the one key with no coercion whatsoever: it has to be
        // a literal true. Publishing an endpoint is not something a truthy
        // string should be able to do on its own.
        if (($api['enabled'] ?? false) !== true) {
            return self::disabled();
        }

        return new self(
            enabled: true,
            basePath: self::normalizeBasePath($api['basePath'] ?? null),
            allowPublicSchema: ($api['allowPublicSchema'] ?? true) !== false,
            rateLimit: self::normalizeInt($api['rateLimit'] ?? null, self::DEFAULT_RATE_LIMIT),
            cacheDuration: self::normalizeInt($api['cacheDuration'] ?? null, 0),
            allowedOrigins: self::normalizeOrigins($api['allowedOrigins'] ?? null),
        );
    }

    /** The route prefix both endpoints hang off: `{basePath}/v{VERSION}`. */
    public function routePrefix(): string
    {
        return $this->basePath . '/v' . self::VERSION;
    }

    /**
     * Whether an `Origin` header may be answered with CORS headers.
     *
     * Exact match only — no suffix or subdomain matching, which is the
     * classic way an allowlist ends up admitting `evil-example.com` for
     * `example.com`. The wildcard is honoured because a menu served from
     * the public schema is public data; the API never sets
     * `Access-Control-Allow-Credentials` and never authenticates from a
     * cookie, so a wildcard cannot be used to read a *browsing user's* data.
     */
    public function isOriginAllowed(?string $origin): bool
    {
        if ($origin === null || $this->allowedOrigins === []) {
            return false;
        }

        return in_array('*', $this->allowedOrigins, true)
            || in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * What to send back as `Access-Control-Allow-Origin` for a request from
     * this origin, or null when the origin isn't allowed.
     *
     * A configured wildcard is echoed as `*` rather than as the caller's own
     * origin: `*` is cacheable by an intermediary for every origin, and
     * echoing would make one origin's cached response the answer for all of
     * them anyway. An allowlisted origin is echoed exactly, paired with
     * `Vary: Origin` by the controller.
     */
    public function allowOriginHeader(?string $origin): ?string
    {
        if (!$this->isOriginAllowed($origin)) {
            return null;
        }

        return in_array('*', $this->allowedOrigins, true) ? '*' : $origin;
    }

    private static function normalizeBasePath(mixed $value): string
    {
        if (!is_string($value)) {
            return self::DEFAULT_BASE_PATH;
        }

        $value = trim(trim($value), '/');

        if ($value === '' || !preg_match(self::BASE_PATH_PATTERN, $value)) {
            return self::DEFAULT_BASE_PATH;
        }

        // `.` and `..` segments would resolve the route somewhere other than
        // where it reads, so they are rejected outright rather than
        // collapsed — the grammar above already admits dots inside a segment
        // (`api.v1` is a fine segment), which is why this is a second check.
        foreach (explode('/', $value) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return self::DEFAULT_BASE_PATH;
            }
        }

        return $value;
    }

    /** A non-negative int, or the default. Digit strings are accepted; nothing else is. */
    private static function normalizeInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : $default;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int)$value;
        }

        return $default;
    }

    /**
     * @return string[]
     */
    private static function normalizeOrigins(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $origins = [];

        foreach ($value as $origin) {
            if (!is_string($origin)) {
                continue;
            }

            $origin = rtrim(trim($origin), '/');

            if ($origin === '*') {
                // One wildcard makes the rest meaningless; say so in the
                // value rather than leaving a list that reads as narrower
                // than it is.
                return ['*'];
            }

            if (preg_match(self::ORIGIN_PATTERN, $origin)) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }
}

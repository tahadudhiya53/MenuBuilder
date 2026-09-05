<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use DateTime;
use DateTimeZone;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

/**
 * The pure half of the GraphQL surface: argument normalization, schema-scope
 * component names, the audience a GraphQL request resolves for, and the
 * value shapes the item type hands back.
 *
 * Separated from the type/query classes for the same reason
 * {@see MenuBuilderFieldHelper} is separated from the field: everything here
 * is decidable without a booted Craft application, so the rules that actually
 * gate the surface — what counts as a handle, which viewport strings are
 * real, who a GraphQL caller *is* — are unit-testable rather than only
 * reachable through a real schema and a real database.
 *
 * Every normalizer fails closed and returns `null` for anything it does not
 * recognize. A GraphQL argument is attacker-controlled input; the resolver
 * turns a `null` here into an empty result, never into a database lookup on
 * a value it could not vouch for.
 */
class MenuBuilderGqlHelper
{
    /**
     * The schema-component namespace this plugin adds, one entry per menu:
     * `menuBuilderGroups.{uid}:read`.
     *
     * Menus are scoped by **UID**, not by handle or ID: a schema is Project
     * Config-adjacent, long-lived and edited by hand, and a UID is the only
     * identifier for a menu that survives a rename (see
     * {@see \Tahadudhiya\MenuBuilder\fields\MenuBuilderField}, which stores
     * a UID for exactly the same reason).
     */
    public const SCHEMA_COMPONENT_PREFIX = 'menuBuilderGroups.';

    /** Craft's own handle grammar, and the one MenuBuilderGroup validates against. */
    private const HANDLE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

    /**
     * A URI longer than this is not a page anybody is on. The cap exists
     * because `currentUri` is echoed into nothing but a path comparison, but
     * it *is* part of the GraphQL result-cache key — an unbounded argument
     * is an unbounded number of cache entries.
     */
    private const MAX_URI_LENGTH = 2048;

    /**
     * The scope component that gates reading one menu, or null when there is
     * no UID to gate on (an unsaved menu, which no schema can name).
     */
    public static function scopeComponent(?string $uid): ?string
    {
        $uid = is_string($uid) ? trim($uid) : '';

        return $uid === '' ? null : self::SCHEMA_COMPONENT_PREFIX . $uid;
    }

    /**
     * A menu or site handle, or null for anything that isn't one.
     *
     * Checked rather than passed straight through: `getByHandle()` would
     * happily take an arbitrary string, and answering "no such menu" after a
     * query is a slower and noisier way to say what the grammar already
     * says. Craft's handles are also the only values these lookups can ever
     * match, so a rejection here loses nothing.
     */
    public static function normalizeHandle(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 255 || !preg_match(self::HANDLE_PATTERN, $value)) {
            return null;
        }

        return $value;
    }

    /** A positive site ID, or null. Digit strings are accepted; anything else is not. */
    public static function normalizeSiteId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value) && (int)$value > 0) {
            return (int)$value;
        }

        return null;
    }

    /**
     * One of {@see MobileHelper::VIEWPORTS}, or null for "don't reshape".
     *
     * Deliberately stricter than {@see MobileHelper::isVisibleOn()}, which
     * treats an unknown viewport as "show everything" so a typo in a template
     * renders the menu rather than an empty landmark. Here a typo can be
     * answered properly — the argument either names a viewport or it doesn't,
     * and "don't reshape" is the same result an omitted argument gets.
     */
    public static function normalizeViewport(mixed $value): ?string
    {
        return is_string($value) && in_array($value, MobileHelper::VIEWPORTS, true) ? $value : null;
    }

    /**
     * The URI to mark active state against, or null when none was asked for.
     *
     * A GraphQL request has no "current page" of its own — it is served from
     * the API endpoint, not from the page whose navigation is being built —
     * so active state is only ever computed against a URI the *caller*
     * names. See {@see \Tahadudhiya\MenuBuilder\gql\MenuBuilderNavigationResolver}
     * for why that argument, rather than the request, is the only cache-safe
     * source for it.
     */
    public static function normalizeCurrentUri(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > self::MAX_URI_LENGTH) {
            return null;
        }

        return $value;
    }

    /**
     * The audience a GraphQL request resolves a menu for: **nobody**.
     *
     * Craft caches GraphQL results by (site, schema, query, variables) and by
     * nothing about the caller — `craft\services\Gql::executeQuery()`. So a
     * tree resolved for whoever happened to send the request would be handed
     * back to every later caller sharing that key: an admin's query would
     * cache the items only logged-in users may see, and the next anonymous
     * request would read them out of the shared entry. That is precisely the
     * "user-specific state in a shared cache" failure the whole resolve
     * pipeline is arranged to avoid (ARCHITECTURE.md "Caching").
     *
     * Making the audience a constant instead of a request property makes the
     * result a pure function of the arguments, which is what makes it
     * cacheable at all. Items restricted to logged-in users or to a user
     * group are therefore absent from a GraphQL response; items restricted to
     * logged-out visitors are present. Date-range, environment and site rules
     * are unaffected — none of them are about who is asking.
     *
     * @param DateTime|null $now Overridable so the date-range half is testable without waiting.
     */
    public static function anonymousContext(
        ?int $currentSiteId,
        DateTimeZone $timezone,
        ?string $environment,
        ?DateTime $now = null,
    ): VisibilityContext {
        return new VisibilityContext(
            isLoggedIn: false,
            userGroupIds: [],
            currentSiteId: $currentSiteId,
            now: $now ?? new DateTime('now', $timezone),
            environment: $environment,
            timezone: $timezone,
        );
    }

    /**
     * An attribute bag as a list of `{name, value}` pairs.
     *
     * GraphQL has no map type and Craft ships no JSON scalar, so an
     * open-ended bag has to be a list of pairs — the same shape Craft's own
     * `TableRow`-adjacent surfaces take. Order is the bag's, and both halves
     * are strings.
     *
     * @param array<string,string> $attributes
     * @return list<array{name: string, value: string}>
     */
    public static function attributePairs(array $attributes): array
    {
        $pairs = [];

        foreach ($attributes as $name => $value) {
            $pairs[] = ['name' => (string)$name, 'value' => (string)$value];
        }

        return $pairs;
    }

    /**
     * An item's custom field values as a list of typed entries.
     *
     * Fed the **serialized** form of a menu item's Craft field values —
     * what each field itself defines as its storage shape — so this helper
     * stays free of Craft and of any knowledge of individual field types.
     * The type is read back off the value. Each entry exposes the value
     * under whichever of the typed accessors actually fits, plus a string
     * form, so a consumer can take the loose one or the exact one:
     *
     * - a boolean is `true`/`false` on `booleanValue`, and `"true"`/`"false"`
     *   on `value` — never `"1"`/`""`, which is what a bare string cast of a
     *   PHP bool produces and is indistinguishable from real content;
     * - a number is on `numberValue`, and on `intValue` as well when it is a
     *   whole number (an asset field stores an asset **ID**, which is what a
     *   consumer feeds back into Craft's own `asset(id:)` query);
     * - everything else is a string.
     *
     * A field whose serialized value is **not** a scalar — a relation field
     * (a list of element IDs), a Matrix field (its blocks), a Table field —
     * has no honest scalar representation, so `value` stays null and the
     * whole serialized value is offered JSON-encoded on `jsonValue`
     * instead. Flattening a Matrix field into a string, or picking one of
     * its ids to stand for it, would be a guess at a shape only the field
     * itself knows.
     *
     * `jsonValue` is populated for scalars too, so a client that wants one
     * uniform accessor has one.
     *
     * @param array<string,mixed> $values
     * @return list<array{handle: string, value: string|null, booleanValue: bool|null, numberValue: float|null, intValue: int|null, jsonValue: string|null}>
     */
    public static function customFieldEntries(array $values): array
    {
        $entries = [];

        foreach ($values as $handle => $value) {
            $json = self::jsonOrNull($value);

            if (is_bool($value)) {
                $entries[] = [
                    'handle' => (string)$handle,
                    'value' => $value ? 'true' : 'false',
                    'booleanValue' => $value,
                    'numberValue' => null,
                    'intValue' => null,
                    'jsonValue' => $json,
                ];

                continue;
            }

            if (is_int($value) || is_float($value)) {
                $entries[] = [
                    'handle' => (string)$handle,
                    'value' => (string)$value,
                    'booleanValue' => null,
                    'numberValue' => (float)$value,
                    'intValue' => is_int($value) ? $value : null,
                    'jsonValue' => $json,
                ];

                continue;
            }

            if (is_string($value)) {
                $entries[] = [
                    'handle' => (string)$handle,
                    'value' => $value,
                    'booleanValue' => null,
                    'numberValue' => null,
                    'intValue' => null,
                    'jsonValue' => $json,
                ];

                continue;
            }

            if ($value === null || $json === null) {
                // Nothing storable and nothing encodable — a resource, a
                // closure, an object with no JSON form. Dropped rather than
                // reported as an empty field, which would be a lie about
                // what the item holds.
                continue;
            }

            $entries[] = [
                'handle' => (string)$handle,
                'value' => null,
                'booleanValue' => null,
                'numberValue' => null,
                'intValue' => null,
                'jsonValue' => $json,
            ];
        }

        return $entries;
    }

    /**
     * `$value` as a JSON string, or null when it has no JSON form.
     *
     * Guarded rather than trusted: a third-party field's serialized value is
     * whatever that field says it is, and an unencodable one must not turn a
     * whole GraphQL response into an error.
     */
    private static function jsonOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? null : $json;
    }
}

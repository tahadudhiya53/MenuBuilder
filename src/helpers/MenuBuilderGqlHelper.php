<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use DateTime;
use DateTimeZone;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

/**
 * The pure half of the GraphQL surface: argument normalization, schema-scope component names, the
 * audience a GraphQL request resolves for, and the value shapes the item type hands back.
*/
class MenuBuilderGqlHelper
{
    /**
     * The schema-component namespace this plugin adds, one entry per menu:
     * `menuBuilderGroups.{uid}:read`.
    */
    public const SCHEMA_COMPONENT_PREFIX = 'menuBuilderGroups.';

    /**
     * Craft's own handle grammar, and the one MenuBuilderGroup validates against.
    */
    private const HANDLE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

    /**
     * A URI longer than this is not a page anybody is on.
    */
    private const MAX_URI_LENGTH = 2048;

    /**
     * The scope component that gates reading one menu, or null when there is no UID to gate on (an
     * unsaved menu, which no schema can name).
    */
    public static function scopeComponent(?string $uid): ?string
    {
        $uid = is_string($uid) ? trim($uid) : '';

        return $uid === '' ? null : self::SCHEMA_COMPONENT_PREFIX . $uid;
    }

    /**
     * A menu or site handle, or null for anything that isn't one.
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

    /**
     * A positive site ID, or null.
    */
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
    */
    public static function normalizeViewport(mixed $value): ?string
    {
        return is_string($value) && in_array($value, MobileHelper::VIEWPORTS, true) ? $value : null;
    }

    /**
     * The URI to mark active state against, or null when none was asked for.
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
                // Nothing storable and nothing encodable — a resource, a closure, an object with
                // no JSON form.
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

<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * The mobile-navigation model, in one place — the same "one grammar, one fail-closed reader"
 * shape as {@see IconHelper} and {@see BadgeHelper}.
*/
class MobileHelper
{
    /**
     * The `metadata` key everything here lives under.
    */
    public const METADATA_KEY = 'mobile';

    public const VIEWPORT_DESKTOP = 'desktop';
    public const VIEWPORT_MOBILE = 'mobile';

    /**
     * The viewports a tree can be resolved for.
    */
    public const VIEWPORTS = [self::VIEWPORT_DESKTOP, self::VIEWPORT_MOBILE];

    public const VISIBILITY_BOTH = 'both';
    public const VISIBILITY_DESKTOP_ONLY = 'desktopOnly';
    public const VISIBILITY_MOBILE_ONLY = 'mobileOnly';

    /**
     * The closed set of per-item viewport visibilities.
    */
    public const VISIBILITIES = [
        self::VISIBILITY_BOTH,
        self::VISIBILITY_DESKTOP_ONLY,
        self::VISIBILITY_MOBILE_ONLY,
    ];

    /**
     * Columns become one stacked list on mobile — the default, and what a 390px screen can
     * actually show.
    */
    public const MEGA_STACK = 'stack';

    /**
     * Keep the column grouping on mobile; for a theme whose columns are narrow enough to survive.
    */
    public const MEGA_COLUMNS = 'columns';

    /**
     * Render no panel on mobile at all: the parent stands alone, its children unreachable there.
    */
    public const MEGA_HIDE = 'hide';

    /**
     * The closed set of mobile mega-menu behaviours.
    */
    public const MEGA_BEHAVIORS = [self::MEGA_STACK, self::MEGA_COLUMNS, self::MEGA_HIDE];

    /**
     * Bounds for a mobile order override.
    */
    public const ORDER_MIN = 0;
    public const ORDER_MAX = 9999;

    /**
     * The normalized mobile config for an item's whole `metadata` bag — a fail-closed read, so a
     * row written straight into the database, or one saved by an older release, reads back as
     * defaults rather than as whatever it contains.
     *
     * @param array<mixed,mixed> $metadata
     * @return array{visibility?: string, order?: int, collapsible?: bool, megaMenu?: string}
    */
    public static function config(array $metadata): array
    {
        $stored = $metadata[self::METADATA_KEY] ?? null;

        if (!is_array($stored)) {
            return [];
        }

        $config = [];

        $visibility = self::visibility($stored['visibility'] ?? null);
        if ($visibility !== self::VISIBILITY_BOTH) {
            $config['visibility'] = $visibility;
        }

        $order = self::order($stored['order'] ?? null);
        if ($order !== null) {
            $config['order'] = $order;
        }

        $collapsible = self::collapsible($stored['collapsible'] ?? null);
        if ($collapsible !== null) {
            $config['collapsible'] = $collapsible;
        }

        $megaMenu = self::megaMenuBehavior($stored['megaMenu'] ?? null);
        if ($megaMenu !== self::MEGA_STACK) {
            $config['megaMenu'] = $megaMenu;
        }

        return $config;
    }

    /**
     * The visibility of a stored/posted value.
    */
    public static function visibility(mixed $stored): string
    {
        if (!is_string($stored)) {
            return self::VISIBILITY_BOTH;
        }

        $value = trim($stored);

        return in_array($value, self::VISIBILITIES, true) ? $value : self::VISIBILITY_BOTH;
    }

    /**
     * True when the value is storable as a visibility at all — absent, blank, or one of {@see
     * VISIBILITIES}.
    */
    public static function isValidVisibility(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && in_array(trim($value), self::VISIBILITIES, true);
    }

    /**
     * Whether an item with this config appears in the given viewport.
     *
     * @param array<string,mixed> $config As returned by {@see config()}.
    */
    public static function isVisibleOn(string $viewport, array $config): bool
    {
        $visibility = self::visibility($config['visibility'] ?? null);

        if ($visibility === self::VISIBILITY_DESKTOP_ONLY) {
            return $viewport !== self::VIEWPORT_MOBILE;
        }

        if ($visibility === self::VISIBILITY_MOBILE_ONLY) {
            return $viewport !== self::VIEWPORT_DESKTOP;
        }

        return true;
    }

    /**
     * A mobile sort override, clamped into range, or null when there is none.
    */
    public static function order(mixed $stored): ?int
    {
        if (is_bool($stored) || $stored === null || $stored === '') {
            return null;
        }

        if (!is_int($stored) && !(is_string($stored) && ctype_digit(trim($stored)))) {
            return null;
        }

        $order = is_int($stored) ? $stored : (int)trim($stored);

        return max(self::ORDER_MIN, min(self::ORDER_MAX, $order));
    }

    /**
     * True when the value is storable as an order at all — absent, blank, or an
     * in-or-out-of-range integer.
    */
    public static function isValidOrder(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return !is_bool($value) && (is_int($value) || (is_string($value) && ctype_digit(trim($value))));
    }

    /**
     * The explicit collapsible override, or null for "no override" — which is not the same as
     * `false`.
    */
    public static function collapsible(mixed $stored): ?bool
    {
        if (is_bool($stored)) {
            return $stored;
        }

        // Lightswitches and JSON round-trips both hand back the string forms.
        if ($stored === '1' || $stored === 1) {
            return true;
        }

        if ($stored === '0' || $stored === 0) {
            return false;
        }

        return null;
    }

    /**
     * How a mega-menu parent's panel behaves on mobile.
    */
    public static function megaMenuBehavior(mixed $stored): string
    {
        if (!is_string($stored)) {
            return self::MEGA_STACK;
        }

        $value = trim($stored);

        return in_array($value, self::MEGA_BEHAVIORS, true) ? $value : self::MEGA_STACK;
    }

    /**
     * True when the value is storable as a mega-menu behaviour at all.
    */
    public static function isValidMegaMenuBehavior(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && in_array(trim($value), self::MEGA_BEHAVIORS, true);
    }

    /**
     * The storage form built from the four discrete CP form fields, or `[]` when every one of them
     * is at its default.
     *
     * @return array{visibility?: string, order?: int, collapsible?: bool, megaMenu?: string}
    */
    public static function fromForm(mixed $visibility, mixed $order, mixed $collapsible, mixed $megaMenu): array
    {
        return self::config([self::METADATA_KEY => [
            'visibility' => $visibility,
            'order' => $order,
            'collapsible' => $collapsible,
            'megaMenu' => $megaMenu,
        ]]);
    }

    /**
     * The value for `data-mb-viewport` on a rendered item, or null when the item belongs to both
     * and the attribute would say nothing.
     *
     * @param array<string,mixed> $config
    */
    public static function viewportAttribute(array $config): ?string
    {
        return match (self::visibility($config['visibility'] ?? null)) {
            self::VISIBILITY_DESKTOP_ONLY => self::VIEWPORT_DESKTOP,
            self::VISIBILITY_MOBILE_ONLY => self::VIEWPORT_MOBILE,
            default => null,
        };
    }
}

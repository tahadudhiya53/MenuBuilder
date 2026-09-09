<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * The badge model, in one place — the same "one grammar, one reader" shape as {@see IconHelper}.
*/
class BadgeHelper
{
    public const STYLE_DEFAULT = 'default';
    public const STYLE_INFO = 'info';
    public const STYLE_SUCCESS = 'success';
    public const STYLE_WARNING = 'warning';
    public const STYLE_CRITICAL = 'critical';

    /**
     * The closed set of styles.
    */
    public const STYLES = [
        self::STYLE_DEFAULT,
        self::STYLE_INFO,
        self::STYLE_SUCCESS,
        self::STYLE_WARNING,
        self::STYLE_CRITICAL,
    ];

    /**
     * Base class the macro always emits for a badge; the style adds a `--<style>` modifier.
    */
    public const BASE_CLASS = 'menu-builder-badge';

    /**
     * Canonical storage form of a posted/imported badge text, or null for "no badge".
    */
    public static function normalizeText(?string $value): ?string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', (string)$value));

        return $value === '' ? null : $value;
    }

    /**
     * The badge text as a template should see it — fail-closed read over whatever is stored, so a
     * row written straight into the database reads back normalized rather than raw.
    */
    public static function text(?string $stored): ?string
    {
        return self::normalizeText($stored);
    }

    /**
     * The style of a stored/posted value, or null when it is absent, blank, the default, or **not a
     * known style**.
     *
     * @param mixed $stored
    */
    public static function style(mixed $stored): ?string
    {
        if (!is_string($stored)) {
            return null;
        }

        $style = strtolower(trim($stored));

        // The default carries no modifier class, so it is stored and read back as "no style" rather
        // than as a class nothing styles.
        if ($style === '' || $style === self::STYLE_DEFAULT) {
            return null;
        }

        return in_array($style, self::STYLES, true) ? $style : null;
    }

    /**
     * True when the value is storable as a style at all — i.e.
    */
    public static function isValidStyle(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), self::STYLES, true);
    }

    /**
     * True when there is a badge to render at all — text is what makes a badge, a style alone is
     * nothing.
    */
    public static function hasBadge(?string $text): bool
    {
        return self::text($text) !== null;
    }

    /**
     * The class list for a badge: the base class, plus a `--<style>` modifier when the style is a
     * known one.
    */
    public static function cssClass(mixed $style): string
    {
        $style = self::style($style);

        return $style === null ? self::BASE_CLASS : self::BASE_CLASS . ' ' . self::BASE_CLASS . '--' . $style;
    }
}

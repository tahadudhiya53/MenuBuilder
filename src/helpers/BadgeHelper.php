<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * The badge model, in one place — the same "one grammar, one reader"
 * shape as {@see IconHelper}.
 *
 * A badge is the short flag an editor hangs off an item's label:
 *
 *   Products [NEW]      Sale [SALE]      Docs [BETA]
 *
 * It is **two** values, stored in two places that already exist:
 *
 *   text   the existing `badge` varchar(255) column, present since
 *          Install.php — free text, exactly what the editor typed
 *   style  `metadata['badgeStyle']`, one of {@see STYLES} or absent
 *
 * No new column: `metadata` is the model's documented extension point
 * (mega menu and dynamic sources already live there), the style is a
 * small closed enum rather than queryable data, and an item that has
 * never had a style set simply has no key — which reads back as
 * {@see STYLE_DEFAULT}.
 *
 * ## Text is text; style is markup-adjacent
 *
 * The two halves need opposite treatment, and conflating them is how a
 * badge would become an injection vector:
 *
 * - **Text** is never markup and is never sanitized into something else.
 *   `<script>alert(1)</script>` typed into the badge field is a badge
 *   reading `<script>alert(1)</script>`, escaped by Twig's autoescaping
 *   at render time like any other string. Stripping `<` here would
 *   silently mangle legitimate badges (`<3`, `A & B`, `50% OFF`) while
 *   adding nothing: the safety comes from escaping at the boundary, and
 *   the bundled macro emits `{{ node.badge }}`, never `|raw`.
 * - **Style** *is* interpolated into a `class` attribute, so it is never
 *   free text. {@see style()} fails closed against the allowlist: an
 *   unknown value — a legacy row, a hand-written database update, a
 *   crafted post — reads back as null, and the macro emits only the
 *   base class. There is no code path from editor input to a class
 *   attribute that isn't one of the constants below.
 *
 * An empty badge is not a badge: whitespace-only text normalizes to
 * null, and a style with no text renders nothing at all
 * ({@see hasBadge()}), so a style left over from a cleared badge can't
 * produce an empty pill in the markup.
 */
class BadgeHelper
{
    public const STYLE_DEFAULT = 'default';
    public const STYLE_INFO = 'info';
    public const STYLE_SUCCESS = 'success';
    public const STYLE_WARNING = 'warning';
    public const STYLE_CRITICAL = 'critical';

    /** The closed set of styles. Anything else is not a style. */
    public const STYLES = [
        self::STYLE_DEFAULT,
        self::STYLE_INFO,
        self::STYLE_SUCCESS,
        self::STYLE_WARNING,
        self::STYLE_CRITICAL,
    ];

    /** Base class the macro always emits for a badge; the style adds a `--<style>` modifier. */
    public const BASE_CLASS = 'menu-builder-badge';

    /**
     * Canonical storage form of a posted/imported badge text, or null for
     * "no badge". Runs at validation time (see
     * MenuBuilderItem::validateBadge()) so every write path stores the
     * same shape.
     *
     * Whitespace only: outer trim plus an inner collapse, which also
     * folds the newlines and tabs a paste can smuggle in — a badge is a
     * single short run of text, and 'NEW' must not be two different
     * stored values depending on how it was pasted. The characters
     * themselves are left alone; see the class docblock.
     */
    public static function normalizeText(?string $value): ?string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', (string)$value));

        return $value === '' ? null : $value;
    }

    /**
     * The badge text as a template should see it — fail-closed read over
     * whatever is stored, so a row written straight into the database
     * reads back normalized rather than raw.
     */
    public static function text(?string $stored): ?string
    {
        return self::normalizeText($stored);
    }

    /**
     * The style of a stored/posted value, or null when it is absent,
     * blank, the default, or **not a known style**. Null means "no
     * modifier class" — never "emit what was stored".
     *
     * @param mixed $stored
     */
    public static function style(mixed $stored): ?string
    {
        if (!is_string($stored)) {
            return null;
        }

        $style = strtolower(trim($stored));

        // The default carries no modifier class, so it is stored and read
        // back as "no style" rather than as a class nothing styles.
        if ($style === '' || $style === self::STYLE_DEFAULT) {
            return null;
        }

        return in_array($style, self::STYLES, true) ? $style : null;
    }

    /** True when the value is storable as a style at all — i.e. absent, blank, or one of {@see STYLES}. */
    public static function isValidStyle(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), self::STYLES, true);
    }

    /** True when there is a badge to render at all — text is what makes a badge, a style alone is nothing. */
    public static function hasBadge(?string $text): bool
    {
        return self::text($text) !== null;
    }

    /**
     * The class list for a badge: the base class, plus a `--<style>`
     * modifier when the style is a known one. Built here rather than in
     * the template so the allowlist is the only source of the modifier.
     */
    public static function cssClass(mixed $style): string
    {
        $style = self::style($style);

        return $style === null ? self::BASE_CLASS : self::BASE_CLASS . ' ' . self::BASE_CLASS . '--' . $style;
    }
}

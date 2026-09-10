<?php

namespace Tahadudhiya\MenuBuilder\models;

/**
 * Resolved, validated mega-menu configuration for one node — built from
 * `MenuBuilderItem::$metadata['megaMenu']` in MenuBuilderResolver. Never
 * persisted directly; the raw config lives in the item's `metadata` bag.
 *
 * The column bounds live here as well, because four places have to agree
 * about them and none of them is a better owner: ItemsController clamps a
 * posted parent's `columns` and a posted child's `megaMenuColumn`,
 * MenuBuilderItem::validateMegaMenu() refuses a stored value outside them,
 * and MenuBuilderResolver falls back to the minimum for a row that predates
 * that validation. Written out four times, they were four chances for the
 * form, the validator and the renderer to disagree about what a legal column
 * is.
 */
final class MenuBuilderMegaMenuConfig
{
    /** A mega menu has at least one column, and one is also the fallback for an unusable stored value. */
    public const MIN_COLUMNS = 1;

    /**
     * The ceiling. Not a layout constraint — the bundled macro emits a
     * `--columns-N` class and lets CSS decide — but a bound on a number that
     * reaches a class attribute and a grid definition, so it is closed
     * rather than open.
     */
    public const MAX_COLUMNS = 6;

    public function __construct(
        public readonly int $columns,
    ) {
    }

    /**
     * A posted column count brought inside the bounds. Used on the write
     * path, where an out-of-range number means a form or a client sent
     * something nobody meant and the nearest legal value is the honest
     * answer — {@see isValidColumns()} is the read/validate-path counterpart
     * that refuses instead.
     */
    public static function clampColumns(int $columns): int
    {
        return max(self::MIN_COLUMNS, min(self::MAX_COLUMNS, $columns));
    }

    /**
     * True when a stored/posted value is a legal column number: an `int`
     * within the bounds, and nothing else. Deliberately strict about the
     * type — a `"3"` or a `true` in a `metadata` bag means the value did not
     * come through the form, so it is reported rather than cast.
     */
    public static function isValidColumns(mixed $columns): bool
    {
        return is_int($columns) && $columns >= self::MIN_COLUMNS && $columns <= self::MAX_COLUMNS;
    }
}

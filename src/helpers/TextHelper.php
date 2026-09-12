<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * Pure string helpers for values that have to fit a database column.
*/
class TextHelper
{
    /**
     * Shortens a value to at most `$maxLength` **characters**, counted the way the `varchar(N)`
     * columns in `migrations/Install.php` count them.
     *
     * Character-based rather than byte-based, for two reasons that both surfaced as a save the
     * editor was told simply "didn't work":
     *
     * - `substr()` cuts at a byte offset, so a name of accented or CJK characters was cut *inside*
     *   a UTF-8 sequence, and the resulting invalid string was rejected by the database rather
     *   than stored short.
     * - MySQL counts a `varchar(255)` in characters, so a byte-based limit also shortened a
     *   perfectly storable multibyte value for no reason.
     *
     * A non-positive `$maxLength` yields an empty string, which is what "no room left" means —
     * see {@see \Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService::uniqueHandle()}, where
     * the room left for a base handle shrinks as the numeric suffix grows.
    */
    public static function truncate(string $value, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) : $value;
    }
}

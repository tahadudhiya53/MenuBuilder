<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use DateTime;
use DateTimeZone;
use Throwable;

/**
 * Date-shape checks shared by the two places a `dateRange` visibility bound is inspected:
 * MenuBuilderItem::validateVisibility() (save time) and DateRangeRule (evaluation time).
*/
class DateValidationHelper
{
    /**
     * DateTime's parser silently normalizes an out-of-range calendar date instead of rejecting it
     * (e.g.
    */
    public static function hasValidCalendarDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return true;
        }

        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /**
     * A `dateRange` bound as a DateTime, or null for anything that isn't a
     * well-formed date string.
     *
     * `mixed` on purpose — a directly-posted or imported `visibility` array
     * isn't guaranteed to contain strings, so this is the defensive boundary
     * for both callers: anything of the wrong kind fails closed (null)
     * rather than risking a TypeError.
     *
     * The one implementation shared by the two places a bound is turned into
     * a date — MenuBuilderItem::validateVisibility() at save time and
     * DateRangeRule at evaluation time — for the same reason
     * {@see hasValidCalendarDate()} is shared: what a save accepts and what
     * an evaluation honours must agree. `$timezone` is the only difference
     * between them; null means PHP's ambient default, which is what save-time
     * validation (a range comparison between two bounds read the same way)
     * has always used.
     */
    public static function parseOrNull(mixed $value, ?DateTimeZone $timezone = null): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        if (!self::hasValidCalendarDate($value)) {
            return null;
        }

        try {
            return new DateTime($value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * Date-shape checks shared by the two places a `dateRange` visibility bound
 * is inspected: MenuBuilderItem::validateVisibility() (save time) and
 * DateRangeRule (evaluation time). One implementation, because what a save
 * accepts and what an evaluation honours must agree.
 */
class DateValidationHelper
{
    /**
     * DateTime's parser silently normalizes an out-of-range calendar date
     * instead of rejecting it (e.g. "2026-02-30" becomes March 2), so a
     * typo'd date would otherwise pass through as a shifted date rather
     * than failing closed. Rejects any leading Y-m-d component that isn't a
     * real calendar date; values with an explicit offset/timezone (e.g. a
     * trailing "+02:00") are unaffected — only the date portion is checked.
     */
    public static function hasValidCalendarDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return true;
        }

        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
}

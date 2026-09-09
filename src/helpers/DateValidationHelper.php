<?php

namespace Tahadudhiya\MenuBuilder\helpers;

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
}

<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use DateTime;

/**
 * Config: {"start": "2026-01-01T00:00:00", "end": "2026-02-01T00:00:00"} —
 * either bound optional. Naive (no offset/timezone) values are interpreted
 * in the application's configured timezone (`VisibilityContext::$timezone`)
 * rather than PHP's ambient default, so a "2026-09-01 09:00" entered in the
 * CP means the same instant it did when the editor typed it.
 *
 * Fails closed — hides the item — for anything that would otherwise mean
 * silently showing gated navigation: an unparseable date string, or a start
 * that's after the end (spec §4/§10 forbid guessing at malformed config).
 */
class DateRangeRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        $rawStart = $config['start'] ?? null;
        $rawEnd = $config['end'] ?? null;

        $hasStart = $rawStart !== null && $rawStart !== '';
        $hasEnd = $rawEnd !== null && $rawEnd !== '';

        $start = $hasStart ? $this->toDate($rawStart, $context) : null;
        $end = $hasEnd ? $this->toDate($rawEnd, $context) : null;

        if (($hasStart && $start === null) || ($hasEnd && $end === null)) {
            // Unparseable/non-string bound — the config is malformed, not "no bound".
            return false;
        }

        if ($start !== null && $end !== null && $start > $end) {
            return false;
        }

        if ($start !== null && $context->now < $start) {
            return false;
        }

        if ($end !== null && $context->now > $end) {
            return false;
        }

        return true;
    }

    /**
     * `mixed` on purpose — this is the defensive boundary for persisted
     * config that may not even be a string (a bool, array, or object left
     * over from malformed/legacy data). Anything that isn't a well-formed
     * date string fails closed (null) rather than risking a TypeError.
     */
    private function toDate(mixed $value, VisibilityContext $context): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        if (!$this->hasValidCalendarDate($value)) {
            return null;
        }

        try {
            return new DateTime($value, $context->timezone ?? $context->now->getTimezone());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * DateTime's parser silently normalizes an out-of-range calendar date
     * instead of rejecting it (e.g. "2026-02-30" becomes March 2), so a
     * typo'd persisted date would otherwise pass through as a shifted date
     * rather than failing closed. Reject any leading Y-m-d component that
     * isn't a real calendar date before handing the value to DateTime.
     * Values with an explicit offset/timezone (e.g. trailing "+02:00") are
     * unaffected — the offset is preserved by DateTime itself, this only
     * validates the date portion.
     */
    private function hasValidCalendarDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return true;
        }

        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
}

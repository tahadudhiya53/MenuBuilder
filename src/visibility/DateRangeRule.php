<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use DateTime;
use Tahadudhiya\MenuBuilder\helpers\DateValidationHelper;

/**
 * Config: {"start": "2026-01-01T00:00:00", "end": "2026-02-01T00:00:00"} — either bound optional.
*/
class DateRangeRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        $rawStart = $config['start'] ?? null;
        $rawEnd = $config['end'] ?? null;

        $hasStart = $rawStart !== null && $rawStart !== '';
        $hasEnd = $rawEnd !== null && $rawEnd !== '';

        // A date-range rule with neither bound constrains nothing, so it can only be missing
        // configuration — the CP never persists one and MenuBuilderItem::validateVisibility()
        // rejects it on save.
        if (!$hasStart && !$hasEnd) {
            return false;
        }

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
     * `mixed` on purpose — this is the defensive boundary for persisted config that may not even
     * be a string (a bool, array, or object left over from malformed/legacy data).
    */
    private function toDate(mixed $value, VisibilityContext $context): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        if (!DateValidationHelper::hasValidCalendarDate($value)) {
            return null;
        }

        try {
            return new DateTime($value, $context->timezone ?? $context->now->getTimezone());
        } catch (\Throwable) {
            return null;
        }
    }
}

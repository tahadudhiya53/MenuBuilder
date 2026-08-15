<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use DateTime;

/** Config: {"start": "2026-01-01T00:00:00", "end": "2026-02-01T00:00:00"} — either bound optional. */
class DateRangeRule implements VisibilityRuleInterface
{
    public function passes(array $config, VisibilityContext $context): bool
    {
        $start = !empty($config['start']) ? $this->toDate($config['start']) : null;
        $end = !empty($config['end']) ? $this->toDate($config['end']) : null;

        if ($start !== null && $context->now < $start) {
            return false;
        }

        if ($end !== null && $context->now > $end) {
            return false;
        }

        return true;
    }

    private function toDate(string $value): ?DateTime
    {
        try {
            return new DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }
}

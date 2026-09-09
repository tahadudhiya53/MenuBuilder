<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use craft\helpers\Json;
use Throwable;

/**
 * Decoding/normalizing for the open-ended bags this plugin persists as JSON text columns
 * (`htmlAttributes`, `settings`, `visibility`, `metadata`) and for the ID lists posted into them.
*/
class ConfigHelper
{
    /**
     * Never throws: a bag that isn't decodable JSON, or decodes to a scalar, is treated as an empty
     * bag rather than failing a whole tree read on one malformed row.
     *
     * @return array<mixed,mixed>
    */
    public static function decodeJsonBag(?string $json): array
    {
        if (!$json) {
            return [];
        }

        try {
            $decoded = Json::decode($json);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalizes a posted/persisted list of IDs (site IDs, user group IDs) into a de-duplicated
     * list of positive ints.
     *
     * @return int[]
    */
    public static function normalizeIdList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = array_filter(array_map('intval', array_filter($value, 'is_scalar')), fn(int $id) => $id > 0);

        return array_values(array_unique($ids));
    }

    /**
     * The strict counterpart to {@see normalizeIdList()}, for ID lists that gate *access* rather
     * than describing a form post: a visibility rule's `groupIds`/`siteIds`.
     *
     * @return int[]|null
    */
    public static function strictIdList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $ids = [];

        foreach ($value as $entry) {
            if (is_int($entry)) {
                if ($entry <= 0) {
                    return null;
                }

                $ids[] = $entry;

                continue;
            }

            // Digit strings only — JSON round-trips and form posts both hand back "5" where the
            // editor picked 5.
            if (!is_string($entry) || $entry === '' || !ctype_digit($entry) || (int)$entry <= 0) {
                return null;
            }

            $ids[] = (int)$entry;
        }

        return array_values(array_unique($ids));
    }

    /**
     * As {@see strictIdList()}, for a visibility rule's string list (an `environment` rule's
     * `environments`).
     *
     * @return string[]|null
    */
    public static function strictStringList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $strings = [];

        foreach ($value as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                return null;
            }

            $strings[] = trim($entry);
        }

        return array_values(array_unique($strings));
    }
}

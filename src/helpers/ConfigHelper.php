<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use craft\helpers\Json;
use Throwable;

/**
 * Decoding/normalizing for the open-ended bags this plugin persists as JSON
 * text columns (`htmlAttributes`, `settings`, `visibility`, `metadata`) and
 * for the ID lists posted into them. Shared by the services and the
 * controllers so every layer reads a stored bag the same way.
 */
class ConfigHelper
{
    /**
     * Never throws: a bag that isn't decodable JSON, or decodes to a scalar,
     * is treated as an empty bag rather than failing a whole tree read on
     * one malformed row.
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
     * Normalizes a posted/persisted list of IDs (site IDs, user group IDs)
     * into a de-duplicated list of positive ints. Anything non-scalar or
     * non-positive is dropped, so a checkbox-select's zero-value padding
     * field and its "bare string when nothing is checked" shape both
     * collapse to an empty list.
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
     * The strict counterpart to {@see normalizeIdList()}, for ID lists that
     * gate *access* rather than describing a form post: a visibility rule's
     * `groupIds`/`siteIds`. Returns `null` — "malformed, fail closed" — for
     * anything that isn't a list of positive integer IDs, instead of
     * silently dropping the bad entries.
     *
     * `normalizeIdList()` deliberately can't be reused here: it accepts any
     * scalar and `intval`s it, so a `true` left in an imported config would
     * become ID 1 and could match an unrelated real user group or site.
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

            // Digit strings only — JSON round-trips and form posts both hand
            // back "5" where the editor picked 5. Anything else (bool, float,
            // "5abc", null, nested array) is malformed.
            if (!is_string($entry) || $entry === '' || !ctype_digit($entry) || (int)$entry <= 0) {
                return null;
            }

            $ids[] = (int)$entry;
        }

        return array_values(array_unique($ids));
    }

    /**
     * As {@see strictIdList()}, for a visibility rule's string list (an
     * `environment` rule's `environments`). Returns `null` for anything that
     * isn't a list of non-empty strings; entries are trimmed, since an
     * environment name never has meaningful surrounding whitespace.
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

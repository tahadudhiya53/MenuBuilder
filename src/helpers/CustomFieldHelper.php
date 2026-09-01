<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use Craft;
use Tahadudhiya\MenuBuilder\models\MenuBuilderCustomField;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * Editor-defined custom fields, in one place — the same "one grammar, one
 * reader" shape as {@see IconHelper} and {@see BadgeHelper}.
 *
 * ## Two halves, on storage that already exists
 *
 * A custom field is a **definition** plus a **value**, and the two live in
 * two different places because they have two different lifetimes:
 *
 *   definition  the menu's existing `settings` bag, under
 *               `MenuBuilderGroupService::CUSTOM_FIELDS_KEY` — handle, name,
 *               type, options. One set per menu, offered to every item in
 *               it (see {@see MenuBuilderCustomField}).
 *   value       the item's existing `metadata` bag, under the single
 *               reserved key {@see VALUES_KEY} — `{handle: scalar}`.
 *
 * Neither is a new store. `metadata` is already this model's documented
 * extension point (mega menu, dynamic sources and the badge style live
 * there); `settings` already carries a menu's site restriction the same
 * way. So custom fields need no migration, duplicate for free (both bags
 * are copied verbatim when an item or a menu is duplicated), and export as
 * ordinary JSON.
 *
 * ## Nothing here can become executable content
 *
 * The type list below is closed and deliberately has no "HTML", "markup" or
 * "template" member. Every value an editor can store is one of: plain text,
 * a number, a boolean, one of a fixed set of strings, a URL, or an asset ID.
 * Templates emit those through Twig's autoescaping like any other string —
 * there is no path from a custom field to a `class` attribute, to raw
 * markup, or to a URL scheme that executes ({@see TYPE_URL} reuses
 * MenuBuilderItem::isPermissiveUrl(), which already denies
 * `javascript:`/`data:`/`vbscript:`).
 *
 * An asset field stores an **ID**, never a URL — the resolved tree is
 * cached, and an asset can be re-uploaded or moved without any menu item
 * changing, so a cached URL would go stale with nothing to invalidate it.
 * Templates resolve it per request via `craft.menuBuilder.customAsset()`,
 * exactly as icons already do.
 *
 * ## Fail closed on read
 *
 * {@see valuesForOutput()} is the only way a stored value reaches a
 * MenuBuilderNode, and it re-checks every value against the current
 * definitions: an undefined handle, a value whose field changed type, a
 * `select` value no longer in the option list, or anything written straight
 * into the database is dropped rather than rendered. Storage is validated
 * on the way in too ({@see validateValue()}), so this is defence in depth,
 * not the only line.
 */
class CustomFieldHelper
{
    /** The single reserved key custom field values live under inside an item's `metadata`. */
    public const VALUES_KEY = 'custom';

    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_NUMBER = 'number';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_SELECT = 'select';
    public const TYPE_URL = 'url';
    public const TYPE_ASSET = 'asset';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_NUMBER,
        self::TYPE_BOOLEAN,
        self::TYPE_SELECT,
        self::TYPE_URL,
        self::TYPE_ASSET,
    ];

    /**
     * Caps. `metadata` is a TEXT column shared with the mega-menu, badge and
     * dynamic-source config, so custom fields are bounded on both axes —
     * how many fields a menu can define and how long a single value can be —
     * rather than being allowed to grow until the *column* is what rejects
     * the save (which surfaces as a save that mysteriously didn't work,
     * with no field error to explain it).
     */
    public const MAX_FIELDS = 20;
    public const MAX_HANDLE_LENGTH = 64;
    public const MAX_NAME_LENGTH = 128;
    public const MAX_TEXT_LENGTH = 255;
    public const MAX_TEXTAREA_LENGTH = 2000;
    public const MAX_OPTIONS = 20;
    public const MAX_OPTION_LENGTH = 64;

    /**
     * @param MenuBuilderCustomField[] $definitions
     * @return array<int,array<string,mixed>> The persisted shape of a menu's definitions.
     */
    public static function definitionsToConfig(array $definitions): array
    {
        return array_values(array_map(fn(MenuBuilderCustomField $field) => $field->toConfig(), $definitions));
    }

    /**
     * Reads a menu's stored definitions back, fail-closed and de-duplicated:
     * a malformed entry is dropped rather than guessed at, a repeated handle
     * keeps only its first definition (two fields answering to one handle
     * have no defined winner at read time), and anything past
     * {@see MAX_FIELDS} is ignored.
     *
     * @return MenuBuilderCustomField[]
     */
    public static function definitionsFromConfig(mixed $config): array
    {
        if (!is_array($config)) {
            return [];
        }

        $definitions = [];

        foreach ($config as $entry) {
            $field = MenuBuilderCustomField::fromConfig($entry);

            if ($field === null || isset($definitions[$field->handle])) {
                continue;
            }

            $definitions[$field->handle] = $field;

            if (count($definitions) >= self::MAX_FIELDS) {
                break;
            }
        }

        return array_values($definitions);
    }

    /**
     * @param MenuBuilderCustomField[] $definitions
     */
    public static function definitionByHandle(array $definitions, string $handle): ?MenuBuilderCustomField
    {
        foreach ($definitions as $definition) {
            if ($definition->handle === $handle) {
                return $definition;
            }
        }

        return null;
    }

    /** @return string[] */
    public static function normalizeOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];

        foreach ($options as $option) {
            if (!is_scalar($option)) {
                continue;
            }

            $option = trim((string)$option);

            if ($option !== '' && !in_array($option, $normalized, true)) {
                $normalized[] = $option;
            }
        }

        return $normalized;
    }

    /**
     * Coerces one posted/imported value into the shape its field stores, or
     * null when there is nothing storable there.
     *
     * Coercion is deliberately narrow — a numeric *string* becomes a number
     * because that is what an HTML form posts, but an array, an object or a
     * value of the wrong kind becomes null instead of being cast into
     * something plausible. {@see validateValue()} is what turns "wrong kind"
     * into an error message; this only decides what would be written.
     */
    public static function normalizeValue(MenuBuilderCustomField $definition, mixed $value): mixed
    {
        if (self::isEmptyValue($value)) {
            // A boolean is the one type with no "empty": an unchecked
            // lightswitch posts nothing, and that means false, not "unset".
            return $definition->type === self::TYPE_BOOLEAN ? false : null;
        }

        return match ($definition->type) {
            self::TYPE_TEXT, self::TYPE_TEXTAREA => is_scalar($value) ? trim((string)$value) : null,
            self::TYPE_URL => is_scalar($value) ? trim((string)$value) : null,
            self::TYPE_SELECT => is_scalar($value) ? (string)$value : null,
            self::TYPE_NUMBER => self::normalizeNumber($value),
            self::TYPE_BOOLEAN => self::normalizeBoolean($value),
            self::TYPE_ASSET => self::normalizeAssetId($value),
            default => null,
        };
    }

    /**
     * The error message for a value that can't be stored in its field, or
     * null when it can. A null value is only an error for a required field —
     * every field is otherwise optional, since an item that simply doesn't
     * use one of a menu's fields is the normal case.
     */
    public static function validateValue(MenuBuilderCustomField $definition, mixed $value): ?string
    {
        $normalized = self::normalizeValue($definition, $value);

        if ($normalized === null) {
            // Distinguish "nothing was given" from "something was given and
            // it isn't storable" — the second is a type error, not a missing
            // value, and saying "is required" for it would be misleading.
            if (!self::isEmptyValue($value)) {
                return Craft::t('menu-builder', '“{name}” must be a {type} value.', [
                    'name' => $definition->name,
                    'type' => $definition->type,
                ]);
            }

            return $definition->required
                ? Craft::t('menu-builder', '“{name}” is required.', ['name' => $definition->name])
                : null;
        }

        return match ($definition->type) {
            self::TYPE_TEXT => self::lengthError($definition, $normalized, self::MAX_TEXT_LENGTH),
            self::TYPE_TEXTAREA => self::lengthError($definition, $normalized, self::MAX_TEXTAREA_LENGTH),
            self::TYPE_URL => self::urlError($definition, (string)$normalized),
            self::TYPE_SELECT => in_array($normalized, $definition->options, true)
                ? null
                : Craft::t('menu-builder', '“{name}” must be one of its configured options.', ['name' => $definition->name]),
            default => null,
        };
    }

    /**
     * The values to persist for one item: only handles the menu actually
     * defines, each coerced to its field's type, with empties dropped so an
     * untouched field leaves no key behind.
     *
     * Unknown handles are dropped rather than kept "just in case" — keeping
     * them would let a tampered or stale post write arbitrary keys into the
     * `metadata` bag, which is exactly what ItemsController::buildMetadata()
     * exists to prevent for every other key in it.
     *
     * @param MenuBuilderCustomField[] $definitions
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function valuesForStorage(array $definitions, array $raw): array
    {
        $values = [];

        foreach ($definitions as $definition) {
            if (!array_key_exists($definition->handle, $raw)) {
                continue;
            }

            $value = self::normalizeValue($definition, $raw[$definition->handle]);

            if ($value === null || $value === false) {
                // `false` is a stored-nothing too: an off lightswitch is the
                // default, so writing it would only bloat every item's bag.
                continue;
            }

            $values[$definition->handle] = $value;
        }

        return $values;
    }

    /**
     * The values a template may see — the fail-closed read described in the
     * class docblock. Everything that wouldn't validate today is dropped,
     * whatever put it there.
     *
     * @param MenuBuilderCustomField[] $definitions
     * @return array<string,mixed>
     */
    public static function valuesForOutput(array $definitions, mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }

        $values = [];

        foreach ($definitions as $definition) {
            if (!array_key_exists($definition->handle, $stored)) {
                continue;
            }

            $raw = $stored[$definition->handle];

            if (self::validateValue($definition, $raw) !== null) {
                continue;
            }

            $value = self::normalizeValue($definition, $raw);

            if ($value === null || $value === false) {
                continue;
            }

            $values[$definition->handle] = $value;
        }

        return $values;
    }

    /**
     * "Nothing was given" — the shapes an untouched control posts. An
     * element select posts its (possibly empty) selection as an array, so
     * an empty one is absence, not a malformed asset ID.
     */
    private static function isEmptyValue(mixed $value): bool
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return true;
        }

        return is_array($value) && (count($value) === 0 || (count($value) === 1 && self::isEmptyValue($value[array_key_first($value)])));
    }

    private static function lengthError(MenuBuilderCustomField $definition, mixed $value, int $max): ?string
    {
        if (!is_string($value) || mb_strlen($value) <= $max) {
            return null;
        }

        return Craft::t('menu-builder', '“{name}” can be at most {max} characters.', [
            'name' => $definition->name,
            'max' => $max,
        ]);
    }

    private static function urlError(MenuBuilderCustomField $definition, string $value): ?string
    {
        if (mb_strlen($value) > self::MAX_TEXTAREA_LENGTH) {
            return self::lengthError($definition, $value, self::MAX_TEXTAREA_LENGTH);
        }

        // Same reader as the item's own URL fields, so a scheme rejected
        // there can't be accepted here — see MenuBuilderItem's
        // DENIED_URL_SCHEMES for why `filter_var()` alone isn't enough.
        return MenuBuilderItem::isPermissiveUrl($value)
            ? null
            : Craft::t('menu-builder', '“{name}” must be a valid URL, path, fragment, mailto: or tel: link.', ['name' => $definition->name]);
    }

    private static function normalizeNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        // Booleans are excluded on purpose: `is_numeric(true)` is false, but
        // a naive `(float)$value` would happily turn `true` into 1.
        if (!is_string($value) || !is_numeric(trim($value))) {
            return null;
        }

        $value = trim($value);

        return ctype_digit(ltrim($value, '-')) ? (int)$value : (float)$value;
    }

    private static function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // The shapes a lightswitch/checkbox actually posts, and their JSON
        // round-trips. Anything else is not a boolean.
        return match ($value) {
            1, '1', 'true' => true,
            0, '0', 'false' => false,
            default => null,
        };
    }

    private static function normalizeAssetId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        // An element select posts its ID as a one-element array.
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && $value !== '' && ctype_digit($value) && (int)$value > 0 ? (int)$value : null;
    }
}

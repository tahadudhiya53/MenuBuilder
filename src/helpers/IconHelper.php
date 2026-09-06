<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * The icon model, in one place.
*/
class IconHelper
{
    public const TYPE_CLASS = 'class';
    public const TYPE_ASSET = 'asset';

    /**
     * Icon handles/classes: letters, digits, space and the separators icon sets actually use.
    */
    private const CLASS_PATTERN = '/^[A-Za-z0-9 _.:\/-]+$/';

    /**
     * Schemes that must never survive in a value a template might hand to `src`/`href`.
    */
    private const BLOCKED_SCHEMES = ['javascript', 'vbscript', 'data', 'file'];

    /**
     * Canonical storage form of a posted/imported icon, or null for "no icon".
    */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        $assetId = self::parseAssetId($value);

        if ($assetId !== null) {
            return self::TYPE_ASSET . ':' . $assetId;
        }

        // An explicit `class:` prefix is accepted on input and dropped on storage, so
        // 'class:icon-cart' and 'icon-cart' can never be two different stored values meaning the
        // same icon.
        if (preg_match('/^class:(.*)$/i', $value, $matches) === 1) {
            $value = trim($matches[1]);
        }

        // Collapse internal runs of whitespace so 'fa fa-cart' and 'fa fa-cart' are one value, and
        // tabs/newlines can't hide in it.
        $value = (string)preg_replace('/\s+/', ' ', $value);

        return $value === '' ? null : $value;
    }

    /**
     * True when the value is safe to emit as an icon class list — the check {@see classValue()}
     * fails closed on.
    */
    public static function isSafeClassValue(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || preg_match(self::CLASS_PATTERN, $value) !== 1) {
            return false;
        }

        // The charset above already excludes markup; this additionally refuses a value shaped like
        // a URL in a dangerous scheme, for the template that renders an icon handle into `src`
        // rather than `class`.
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', $value, $matches) === 1) {
            return !in_array(strtolower($matches[1]), self::BLOCKED_SCHEMES, true);
        }

        return true;
    }

    /**
     * True when a normalized value is storable at all — i.e.
    */
    public static function isValid(?string $value): bool
    {
        $value = self::normalize($value);

        return $value === null
            || self::parseAssetId($value) !== null
            || self::isSafeClassValue($value);
    }

    /**
     * `IconHelper::TYPE_*` for a stored value, or null when there is no (usable) icon.
    */
    public static function type(?string $stored): ?string
    {
        if (self::assetId($stored) !== null) {
            return self::TYPE_ASSET;
        }

        return self::classValue($stored) !== null ? self::TYPE_CLASS : null;
    }

    /**
     * The asset id of an `asset:<id>` icon, or null for every other form.
    */
    public static function assetId(?string $stored): ?int
    {
        return self::parseAssetId(trim((string)$stored));
    }

    /**
     * The class list of a class icon, or null — for "no icon", for an asset icon, and
     * (fail-closed) for any stored value that wouldn't pass validation today.
    */
    public static function classValue(?string $stored): ?string
    {
        $value = self::normalize($stored);

        if ($value === null || self::parseAssetId($value) !== null) {
            return null;
        }

        return self::isSafeClassValue($value) ? $value : null;
    }

    /**
     * Builds the stored value from the CP form's three inputs (an icon source select plus one field
     * per source).
     *
     * @param mixed $asset
    */
    public static function composeFromForm(?string $source, ?string $class, mixed $asset): ?string
    {
        if ($source === self::TYPE_ASSET) {
            $assetId = self::firstElementId($asset);

            return $assetId !== null ? self::TYPE_ASSET . ':' . $assetId : null;
        }

        if ($source === self::TYPE_CLASS) {
            return self::normalize($class);
        }

        // Explicit "no icon" (or a form that posted no source at all) clears the field rather than
        // silently keeping a stale value the editor can no longer see.
        return null;
    }

    /**
     * First id out of an element-select post value (array of ids, a single scalar id, or an
     * empty/blank value), or null.
     *
     * @param mixed $value
    */
    public static function firstElementId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === false || $value === '' || !is_scalar($value)) {
            return null;
        }

        $id = (int)$value;

        return $id > 0 ? $id : null;
    }

    private static function parseAssetId(string $value): ?int
    {
        if (preg_match('/^asset:(\d+)$/i', $value, $matches) !== 1) {
            return null;
        }

        $id = (int)$matches[1];

        return $id > 0 ? $id : null;
    }
}

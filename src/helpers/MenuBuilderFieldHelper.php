<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use craft\helpers\StringHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;

/**
 * The decision logic behind {@see \Tahadudhiya\MenuBuilder\fields\MenuBuilderField}: what a stored
 * value may be, which menus an author is offered, and when a stored selection is an error rather
 * than merely unresolvable.
*/
class MenuBuilderFieldHelper
{
    /**
     * The stored selection points at a menu that no longer exists.
    */
    public const ERROR_MISSING = 'missing';

    /**
     * The stored selection is a real menu, but not one this field offers.
    */
    public const ERROR_NOT_ALLOWED = 'notAllowed';

    /**
     * The stored selection is a real, offered menu that isn't available on the site this element is
     * being saved for — and this field is translatable, so the author *can* pick a different menu
     * here.
    */
    public const ERROR_SITE_MISMATCH = 'siteMismatch';

    /**
     * A stored/posted field value normalized to a menu UID, or null.
    */
    public static function normalizeUid(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        // Craft's own select inputs post this sentinel for the blank option.
        if ($value === '' || strtolower($value) === '__blank__') {
            return null;
        }

        return StringHelper::isUUID($value) ? $value : null;
    }

    /**
     * The `allowedGroupUids` setting normalized: a de-duplicated list of UID-shaped strings.
     *
     * @return string[]
    */
    public static function normalizeUidList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $uids = [];

        foreach ($value as $entry) {
            $uid = self::normalizeUid($entry);

            if ($uid !== null) {
                $uids[] = $uid;
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * Whether a menu is one this field offers.
     *
     * @param string[] $allowedUids
    */
    public static function isAllowed(?string $uid, array $allowedUids): bool
    {
        if ($uid === null) {
            return false;
        }

        return empty($allowedUids) || in_array($uid, $allowedUids, true);
    }

    /**
     * The menus the picker offers, in the order the CP lists them.
     *
     * @param MenuBuilderGroup[] $groups
     * @param string[] $allowedUids
     * @return MenuBuilderGroup[]
    */
    public static function selectableGroups(
        array $groups,
        array $allowedUids,
        bool $includeDisabled,
        ?string $currentUid = null,
    ): array {
        $selectable = [];

        foreach ($groups as $group) {
            $isCurrent = $currentUid !== null && $group->uid === $currentUid;

            if (!$isCurrent && !self::isAllowed($group->uid, $allowedUids)) {
                continue;
            }

            if (!$isCurrent && !$includeDisabled && !$group->enabled) {
                continue;
            }

            $selectable[] = $group;
        }

        return $selectable;
    }

    /**
     * Why a stored selection is invalid, or null when it is fine.
     *
     * @param string[] $allowedUids
    */
    public static function validationError(
        ?string $uid,
        ?MenuBuilderGroup $group,
        array $allowedUids,
        bool $isTranslatable = false,
        ?int $siteId = null,
    ): ?string {
        if ($uid === null) {
            return null;
        }

        if ($group === null) {
            return self::ERROR_MISSING;
        }

        if (!self::isAllowed($uid, $allowedUids)) {
            return self::ERROR_NOT_ALLOWED;
        }

        if ($isTranslatable && !$group->isAvailableForSite($siteId)) {
            return self::ERROR_SITE_MISMATCH;
        }

        return null;
    }

    /**
     * Whether the field input may show a "manage this menu" link into the CP section.
    */
    public static function canLinkToMenu(bool $isAdmin, bool $canView): bool
    {
        return $isAdmin || $canView;
    }
}

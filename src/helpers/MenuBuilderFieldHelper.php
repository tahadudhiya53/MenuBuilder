<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use craft\helpers\StringHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;

/**
 * The decision logic behind {@see \Tahadudhiya\MenuBuilder\fields\MenuBuilderField}:
 * what a stored value may be, which menus an author is offered, and when a
 * stored selection is an error rather than merely unresolvable.
 *
 * Every function here is pure and static for the same reason the visibility
 * rules are ({@see \Tahadudhiya\MenuBuilder\visibility\VisibilityRuleInterface}):
 * the field itself needs a booted Craft application — a field layout, an
 * element, a request — but none of *these* answers do, so the rules that
 * decide whether an author's selection is valid stay unit-testable without a
 * database.
 */
class MenuBuilderFieldHelper
{
    /** The stored selection points at a menu that no longer exists. */
    public const ERROR_MISSING = 'missing';

    /** The stored selection is a real menu, but not one this field offers. */
    public const ERROR_NOT_ALLOWED = 'notAllowed';

    /**
     * The stored selection is a real, offered menu that isn't available on
     * the site this element is being saved for — and this field is
     * translatable, so the author *can* pick a different menu here.
     */
    public const ERROR_SITE_MISMATCH = 'siteMismatch';

    /**
     * A stored/posted field value normalized to a menu UID, or null.
     *
     * UIDs are the identity the field persists — not the handle (which a
     * rename would break) and not the row ID (which is assigned by whichever
     * database created the row, so the same menu can have different IDs in two
     * databases). A UID is a stable *reference*; it resolves only in a database
     * that contains that menu, since menus are database-only and are not
     * project-config entities (see ARCHITECTURE.md "Group persistence —
     * database only").
     *
     * The shape is checked rather than trusted: the value reaches this from
     * a form post, a GraphQL mutation, a content migration or a `setFieldValue()`
     * call, and anything that isn't UID-shaped is a value no menu could ever
     * have, so it collapses to "nothing selected" instead of being carried
     * into a query.
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
     * The `allowedGroupUids` setting normalized: a de-duplicated list of
     * UID-shaped strings. Applied on both read and write, so a hand-edited
     * or partially-applied project config can never widen the field beyond
     * menus it actually names.
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
     * Whether a menu is one this field offers. An empty allow-list means
     * "every menu" — the same convention {@see MenuBuilderGroup::$siteIds}
     * uses for site restrictions, so the two restriction settings in this
     * plugin read the same way.
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
     * Disabled menus are excluded unless the field opts them in — a disabled
     * menu resolves to no tree, so offering one by default would let an
     * author pick something that renders nothing. The **currently stored**
     * menu is always kept, even when it is disabled or has since been
     * dropped from the allow-list: an author opening an unrelated entry must
     * not have their selection silently rewritten by a picker that no longer
     * contains it. Whether that stored value is still *valid* is a separate
     * question, answered by {@see validationError()}.
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
     * Note what is deliberately *not* an error:
     *
     * - **Nothing selected.** Emptiness is Craft's business — the field
     *   layout's "required" flag decides that, not this field.
     * - **A disabled menu.** `enabled` is a publishing state of the menu,
     *   which an editor flips independently of any entry; treating it as a
     *   content error would make every entry pointing at a menu unsavable
     *   the moment somebody turned that menu off. It resolves to no tree,
     *   which is the intended effect of disabling it.
     * - **A site the menu isn't available on, when the field is not
     *   translatable.** One value then covers every site, so a menu
     *   restricted to a subset of sites could never satisfy it — the error
     *   would be unfixable. It's only a mistake the author can act on when
     *   the field is per-site, and only then is it reported.
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
     * Whether the field input may show a "manage this menu" link into the CP
     * section. Selecting a menu is content authoring and needs no MenuBuilder
     * permission; *reaching* the menu editor does, so the link is offered
     * only to someone who could follow it (rule: never render an affordance
     * a permission check would then reject — see ARCHITECTURE.md
     * "Permissions & security").
     */
    public static function canLinkToMenu(bool $isAdmin, bool $canView): bool
    {
        return $isAdmin || $canView;
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * The mobile-navigation model, in one place — the same "one grammar, one
 * fail-closed reader" shape as {@see IconHelper} and {@see BadgeHelper}.
 *
 * ## Why there is no second menu
 *
 * The obvious way to build "a different navigation on small screens" is a
 * second menu: a `mobileGroupId`, its own items, its own tree. This plugin
 * deliberately does not do that, because a duplicated menu duplicates
 * everything attached to a menu and nothing else:
 *
 * - **Two trees to edit.** Every rename, every re-point, every new page has
 *   to be done twice, and the two drift the day someone forgets. The
 *   commonest real complaint about mobile navigation is that it says
 *   something different from the desktop one — a second menu is that bug,
 *   institutionalised.
 * - **Two caches, two invalidations.** {@see MenuBuilderCacheService} keys on
 *   the group; a second group is a second entry that an edit to the first
 *   does not invalidate.
 * - **Active state and breadcrumbs stop agreeing.** Both are derived from a
 *   menu's own hierarchy (see MenuBuilderActiveResolver,
 *   MenuBuilderBreadcrumbService). Two hierarchies for one site means two
 *   answers to "where am I", and the one the visitor is looking at is the one
 *   that isn't driving the `<title>`.
 * - **Visibility rules, link health and syncing all double**, for items that
 *   overwhelmingly point at the same pages.
 *
 * What actually differs between viewports is not *which menu* but **how much
 * of it, in what order, and how deep a level is disclosed**. All three are
 * presentation facts about an item that already exists, so they are stored as
 * presentation metadata on that item:
 *
 *     metadata['mobile'] = [
 *         'visibility' => 'both' | 'desktopOnly' | 'mobileOnly',
 *         'order'      => int,          // sibling order override on mobile
 *         'collapsible'=> bool,         // children start collapsed on mobile
 *         'megaMenu'   => 'stack' | 'columns' | 'hide',
 *     ]
 *
 * No new column and no new table: `metadata` is the model's documented
 * extension point (mega menu, badge style and dynamic sources already live
 * there), and an item that has never been configured for mobile stores
 * nothing at all — {@see fromForm()} omits every default, so the bag stays
 * empty rather than filling with `'both'`.
 *
 * ## What this plugin decides, and what your CSS decides
 *
 * MenuBuilder decides **which items belong to which viewport, in what order,
 * and which branches are disclosures**. It has no opinion whatsoever about
 * *when* a viewport begins: no breakpoint is stored, no media query is
 * emitted, no width is assumed, and no class from any CSS framework appears
 * anywhere. A `data-mb-viewport` attribute on a list item is the whole of the
 * contract — your stylesheet decides at what width, or your template decides
 * with {@see \Tahadudhiya\MenuBuilder\models\MenuBuilderTree::forViewport()}
 * which tree to render at all.
 *
 * ## Why ordering is data and never a CSS `order`
 *
 * A mobile order could in principle be handed to CSS as `order:` on a flex
 * child. It deliberately is not, and the macros never emit one: CSS `order`
 * changes the *visual* sequence while leaving the DOM — and therefore the Tab
 * order and the screen-reader reading order — alone, which is precisely
 * WCAG 1.3.2 (Meaningful Sequence) and 2.4.3 (Focus Order) being broken.
 * Mobile order is applied by **re-sorting the tree**, so the DOM a mobile
 * visitor gets is in the order they see. That means a mobile order is only
 * meaningful in a separately rendered mobile navigation (`forViewport()`);
 * one DOM shared by both viewports is by definition in one order, and
 * {@see \Tahadudhiya\MenuBuilder\models\MenuBuilderNode::mobileOrder()} is
 * then data for a theme to do with as it wishes.
 */
class MobileHelper
{
    /** The `metadata` key everything here lives under. */
    public const METADATA_KEY = 'mobile';

    public const VIEWPORT_DESKTOP = 'desktop';
    public const VIEWPORT_MOBILE = 'mobile';

    /** The viewports a tree can be resolved for. "both" is not one — it's an item's answer, not a request's. */
    public const VIEWPORTS = [self::VIEWPORT_DESKTOP, self::VIEWPORT_MOBILE];

    public const VISIBILITY_BOTH = 'both';
    public const VISIBILITY_DESKTOP_ONLY = 'desktopOnly';
    public const VISIBILITY_MOBILE_ONLY = 'mobileOnly';

    /** The closed set of per-item viewport visibilities. Anything else is not one. */
    public const VISIBILITIES = [
        self::VISIBILITY_BOTH,
        self::VISIBILITY_DESKTOP_ONLY,
        self::VISIBILITY_MOBILE_ONLY,
    ];

    /** Columns become one stacked list on mobile — the default, and what a 390px screen can actually show. */
    public const MEGA_STACK = 'stack';

    /** Keep the column grouping on mobile; for a theme whose columns are narrow enough to survive. */
    public const MEGA_COLUMNS = 'columns';

    /** Render no panel on mobile at all: the parent stands alone, its children unreachable there. */
    public const MEGA_HIDE = 'hide';

    /** The closed set of mobile mega-menu behaviours. */
    public const MEGA_BEHAVIORS = [self::MEGA_STACK, self::MEGA_COLUMNS, self::MEGA_HIDE];

    /**
     * Bounds for a mobile order override. Wide enough to interleave freely,
     * narrow enough that a posted value can't become a sort key nobody
     * intended. Out-of-range values clamp rather than fail: an order is a
     * hint about sequence, and refusing to save an item over one would be
     * out of proportion.
     */
    public const ORDER_MIN = 0;
    public const ORDER_MAX = 9999;

    /**
     * The normalized mobile config for an item's whole `metadata` bag — a
     * fail-closed read, so a row written straight into the database, or one
     * saved by an older release, reads back as defaults rather than as
     * whatever it contains.
     *
     * Returns only the keys that are actually set, so `[]` means "nothing
     * configured" everywhere: in the cached node, in the CP form, and in the
     * `metadata` column.
     *
     * @param array<mixed,mixed> $metadata
     * @return array{visibility?: string, order?: int, collapsible?: bool, megaMenu?: string}
     */
    public static function config(array $metadata): array
    {
        $stored = $metadata[self::METADATA_KEY] ?? null;

        if (!is_array($stored)) {
            return [];
        }

        $config = [];

        $visibility = self::visibility($stored['visibility'] ?? null);
        if ($visibility !== self::VISIBILITY_BOTH) {
            $config['visibility'] = $visibility;
        }

        $order = self::order($stored['order'] ?? null);
        if ($order !== null) {
            $config['order'] = $order;
        }

        $collapsible = self::collapsible($stored['collapsible'] ?? null);
        if ($collapsible !== null) {
            $config['collapsible'] = $collapsible;
        }

        $megaMenu = self::megaMenuBehavior($stored['megaMenu'] ?? null);
        if ($megaMenu !== self::MEGA_STACK) {
            $config['megaMenu'] = $megaMenu;
        }

        return $config;
    }

    /**
     * The visibility of a stored/posted value. Never "emit what was stored":
     * anything absent, blank or unrecognised is {@see VISIBILITY_BOTH}, which
     * is the only safe failure — an item nobody deliberately restricted stays
     * in both navigations rather than vanishing from one.
     */
    public static function visibility(mixed $stored): string
    {
        if (!is_string($stored)) {
            return self::VISIBILITY_BOTH;
        }

        $value = trim($stored);

        return in_array($value, self::VISIBILITIES, true) ? $value : self::VISIBILITY_BOTH;
    }

    /** True when the value is storable as a visibility at all — absent, blank, or one of {@see VISIBILITIES}. */
    public static function isValidVisibility(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && in_array(trim($value), self::VISIBILITIES, true);
    }

    /**
     * Whether an item with this config appears in the given viewport.
     *
     * An unknown viewport answers `true`: this is a *visibility* question,
     * and the fail-closed direction for "should this link exist" is to keep
     * the link. Hiding content because a template passed a typo would be a
     * far worse failure than showing it.
     *
     * @param array<string,mixed> $config As returned by {@see config()}.
     */
    public static function isVisibleOn(string $viewport, array $config): bool
    {
        $visibility = self::visibility($config['visibility'] ?? null);

        if ($visibility === self::VISIBILITY_DESKTOP_ONLY) {
            return $viewport !== self::VIEWPORT_MOBILE;
        }

        if ($visibility === self::VISIBILITY_MOBILE_ONLY) {
            return $viewport !== self::VIEWPORT_DESKTOP;
        }

        return true;
    }

    /**
     * A mobile sort override, clamped into range, or null when there is
     * none. Only integers and digit strings count — a form posts "3" where
     * the editor typed 3, but `true`, `"3rd"` and `[]` are not orders.
     */
    public static function order(mixed $stored): ?int
    {
        if (is_bool($stored) || $stored === null || $stored === '') {
            return null;
        }

        if (!is_int($stored) && !(is_string($stored) && ctype_digit(trim($stored)))) {
            return null;
        }

        $order = is_int($stored) ? $stored : (int)trim($stored);

        return max(self::ORDER_MIN, min(self::ORDER_MAX, $order));
    }

    /** True when the value is storable as an order at all — absent, blank, or an in-or-out-of-range integer. */
    public static function isValidOrder(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return !is_bool($value) && (is_int($value) || (is_string($value) && ctype_digit(trim($value))));
    }

    /**
     * The explicit collapsible override, or null for "no override" — which
     * is not the same as `false`. The default is derived from the node (a
     * branch with children is a disclosure on mobile; a leaf is not), so
     * storing `false` has to mean "this branch is deliberately always open"
     * and absence has to mean "do the usual thing".
     */
    public static function collapsible(mixed $stored): ?bool
    {
        if (is_bool($stored)) {
            return $stored;
        }

        // Lightswitches and JSON round-trips both hand back the string forms.
        if ($stored === '1' || $stored === 1) {
            return true;
        }

        if ($stored === '0' || $stored === 0) {
            return false;
        }

        return null;
    }

    /**
     * How a mega-menu parent's panel behaves on mobile. Fails closed to
     * {@see MEGA_STACK}: an unrecognised value must not become
     * {@see MEGA_HIDE}, which would silently drop links from the only
     * navigation a phone has.
     */
    public static function megaMenuBehavior(mixed $stored): string
    {
        if (!is_string($stored)) {
            return self::MEGA_STACK;
        }

        $value = trim($stored);

        return in_array($value, self::MEGA_BEHAVIORS, true) ? $value : self::MEGA_STACK;
    }

    /** True when the value is storable as a mega-menu behaviour at all. */
    public static function isValidMegaMenuBehavior(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && in_array(trim($value), self::MEGA_BEHAVIORS, true);
    }

    /**
     * The storage form built from the four discrete CP form fields, or `[]`
     * when every one of them is at its default.
     *
     * Defaults are omitted rather than written, for the same reason
     * `badgeStyle` is only stored alongside real badge text: a bag that
     * accumulates `'visibility' => 'both'` on every item is a bag whose
     * emptiness no longer means anything, and every future default change
     * would then have to be migrated instead of simply being the new
     * default.
     *
     * @return array{visibility?: string, order?: int, collapsible?: bool, megaMenu?: string}
     */
    public static function fromForm(mixed $visibility, mixed $order, mixed $collapsible, mixed $megaMenu): array
    {
        return self::config([self::METADATA_KEY => [
            'visibility' => $visibility,
            'order' => $order,
            'collapsible' => $collapsible,
            'megaMenu' => $megaMenu,
        ]]);
    }

    /**
     * The value for `data-mb-viewport` on a rendered item, or null when the
     * item belongs to both and the attribute would say nothing.
     *
     * This is the entire CSS contract, and it is deliberately one attribute
     * with one of two values. Hide the ones that don't belong with
     * `display:none` inside your own media query — not with `visibility`,
     * `opacity` or an off-screen position, all of which leave the link in the
     * accessibility tree and in the Tab order for a viewport it isn't part
     * of. See README.md → Accessibility.
     *
     * @param array<string,mixed> $config
     */
    public static function viewportAttribute(array $config): ?string
    {
        return match (self::visibility($config['visibility'] ?? null)) {
            self::VISIBILITY_DESKTOP_ONLY => self::VIEWPORT_DESKTOP,
            self::VISIBILITY_MOBILE_ONLY => self::VIEWPORT_MOBILE,
            default => null,
        };
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\models;

use Tahadudhiya\MenuBuilder\helpers\BadgeHelper;
use Tahadudhiya\MenuBuilder\helpers\IconHelper;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\helpers\MobileHelper;

/**
 * The Twig-facing representation of a resolved navigation item — hides the
 * database entirely (no IDs to join, no parentId, no sort columns). Built by
 * MenuBuilderResolver from a MenuBuilderItem + its ResolvedLink.
 */
class MenuBuilderNode
{
    /** @var MenuBuilderNode[] */
    public array $children = [];

    public ?MenuBuilderNode $parent = null;

    public bool $isActive = false;

    public bool $isActiveAncestor = false;

    public function __construct(
        public readonly int $id,
        public readonly ?string $handle,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $url,
        public readonly bool $isClickable,
        public readonly bool $isLinkAvailable,
        public readonly string $target,
        public readonly ?string $rel,
        public readonly ?string $cssClass,
        public readonly ?string $htmlId,
        public readonly array $htmlAttributes,
        public readonly ?string $ariaLabel,
        public readonly ?string $titleAttribute,
        public readonly ?string $icon,
        public readonly ?string $badge,
        public readonly ?string $description,
        public readonly ?int $image,
        public readonly bool $featured,
        public readonly int $level,
        /** Mega-menu config for this node when it's the mega-menu parent, null otherwise — see MenuBuilderItem::$metadata['megaMenu']. */
        public readonly ?MenuBuilderMegaMenuConfig $megaMenu = null,
        /** Which column this node belongs to under its mega-menu-enabled parent; meaningless otherwise. */
        public readonly ?int $megaMenuColumn = null,
        /** True when this node was synthesized from a dynamic navigation source rather than a persisted item — see MenuBuilderDynamicNavigationService. */
        public readonly bool $isDynamic = false,
        /**
         * The badge's style — one of BadgeHelper::STYLES or null for the
         * default. Declared last, after the pre-existing defaulted
         * parameters, so every existing positional construction of a node
         * keeps working unchanged.
         */
        public readonly ?string $badgeStyle = null,
        /**
         * The item's editor-defined custom field values, keyed by handle —
         * already checked against the menu's definitions by
         * CustomFieldHelper::valuesForOutput(), so a handle the menu no
         * longer defines, or a value that no longer fits its field's type,
         * never gets this far.
         *
         * Values are plain scalars (text, number, boolean, an option
         * string, a URL, or an asset **ID**) and are emitted through Twig's
         * autoescaping like any other string. An asset field stores the ID,
         * never the URL — see `craft.menuBuilder.customAsset()`, and
         * {@see iconType()} for the same reasoning applied to icons.
         *
         * Declared last, after the existing defaulted parameters, so every
         * positional construction of a node keeps working unchanged.
         *
         * @var array<string,mixed>
         */
        public readonly array $customFields = [],
        /**
         * The item's normalized mobile-presentation config — see
         * {@see MobileHelper}. `[]` means "nothing configured", which is
         * the state of every item that has never been touched on the
         * mobile tab, so the common case costs nothing.
         *
         * This belongs in the cached node: it is a fact about the *item*,
         * decided by an editor, and about nothing to do with the visitor,
         * the request, or the device asking. No user agent is sniffed and
         * no width is guessed anywhere in this plugin — a viewport is
         * something the template or the stylesheet chooses, not something
         * the server detects. That is what keeps one cache entry correct
         * for both viewports (see ARCHITECTURE.md "Caching").
         *
         * Declared last, after the existing defaulted parameters, so every
         * positional construction of a node keeps working unchanged.
         *
         * @var array{visibility?: string, order?: int, collapsible?: bool, megaMenu?: string}
         */
        public readonly array $mobile = [],
    ) {
    }

    /**
     * A copy of this node carrying the given (already-copied) children,
     * with each child's `parent` rewired to the copy.
     *
     * The per-request half of the pipeline — visibility filtering and
     * active-state marking — writes to `children`, `isActive` and
     * `isActiveAncestor`. Those nodes come out of
     * MenuBuilderCacheService, so writing to them in place means writing
     * to the cached tree: harmless with a serializing cache backend (every
     * read hands back a fresh object graph), but a per-request visibility
     * decision baked into a shared cache entry the moment the backend
     * hands back the same instances. Copying here keeps the cached tree
     * immutable by construction rather than by backend choice — the
     * property ARCHITECTURE.md's cache boundary depends on.
     *
     * @param MenuBuilderNode[] $children
     * @param bool $preserveActiveState Keep this node's already-marked active state on the copy.
     *                                  False (the default) for the resolve pipeline, which copies
     *                                  *before* marking. True for a copy made afterwards — see
     *                                  {@see MenuBuilderTree::forViewport()}, which re-filters and
     *                                  re-sorts a tree whose active state is already decided and
     *                                  must survive.
     */
    public function withChildren(array $children, bool $preserveActiveState = false): self
    {
        $copy = clone $this;
        $copy->children = $children;
        $copy->isActive = $preserveActiveState && $this->isActive;
        $copy->isActiveAncestor = $preserveActiveState && $this->isActiveAncestor;

        foreach ($children as $child) {
            $child->parent = $copy;
        }

        return $copy;
    }

    /**
     * The icon, as three read-only derived accessors over the single
     * stored `icon` string — see {@see IconHelper} for the grammar.
     *
     * Derived rather than resolved into extra constructor state on
     * purpose: the node is what gets cached, and an icon's *rendering*
     * (an asset's URL, above all) can change without the item changing,
     * so the tree caches the reference and templates resolve it per
     * request through `craft.menuBuilder.iconAsset(node)`.
     *
     * `iconClass()` fails closed: a value that wouldn't validate today
     * — a legacy row, a direct database write — reads back as null rather
     * than reaching a template.
     */
    public function iconType(): ?string
    {
        return IconHelper::type($this->icon);
    }

    public function iconClass(): ?string
    {
        return IconHelper::classValue($this->icon);
    }

    public function iconAssetId(): ?int
    {
        return IconHelper::assetId($this->icon);
    }

    public function hasIcon(): bool
    {
        return $this->iconType() !== null;
    }

    /**
     * The badge, as derived accessors over the two stored values
     * (`badge` text + `metadata['badgeStyle']`) — see {@see BadgeHelper}.
     *
     * Text is deliberately *not* sanitized here: it is plain text and is
     * escaped where it is rendered. The style is the half that reaches a
     * `class` attribute, and {@see badgeClass()} fails closed on it, so an
     * unknown style can never leave this object as markup.
     *
     * A style with no text is not a badge: {@see hasBadge()} is keyed off
     * the text alone, and the bundled macro renders nothing without it.
     */
    public function hasBadge(): bool
    {
        return BadgeHelper::hasBadge($this->badge);
    }

    /** The badge's class list: the base class plus a `--<style>` modifier for a known style. */
    public function badgeClass(): string
    {
        return BadgeHelper::cssClass($this->badgeStyle);
    }

    /**
     * One custom field value by handle, or `$default` when this item has
     * none. The documented Twig entry point:
     *
     *     {{ node.custom('subtitle') }}
     *     {% if node.custom('featured', false) %}…{% endif %}
     */
    public function custom(string $handle, mixed $default = null): mixed
    {
        return $this->customFields[$handle] ?? $default;
    }

    /** Whether this item has a value for the given custom field. */
    public function hasCustom(string $handle): bool
    {
        return array_key_exists($handle, $this->customFields);
    }

    /**
     * The item's custom HTML attributes, re-checked at render time and
     * stripped of anything unsafe or reserved — see
     * {@see LinkAttributeHelper::filterHtmlAttributes()}. This is what the
     * bundled macros render; `$htmlAttributes` remains the stored bag, for
     * a template that wants to make its own decision about it.
     *
     * Derived rather than filtered into the constructor because the node is
     * what gets cached: a rule tightened in a later release must apply to
     * trees cached before it, the same way `iconClass()` fails closed on a
     * legacy icon value.
     *
     * @return array<string,string>
     */
    public function safeHtmlAttributes(): array
    {
        return LinkAttributeHelper::filterHtmlAttributes($this->htmlAttributes);
    }

    /**
     * Whether following this link leaves the current tab. The bundled macro
     * emits `target` only when this is true, and adds a visually hidden
     * "opens in a new tab" to the link's accessible name — a change of
     * context a sighted user reads from the browser and a screen-reader
     * user otherwise doesn't get told about at all (WCAG 3.2.5).
     *
     * Keyed off the resolved node, so a `target` on a heading — which
     * renders no link — is not announced as opening anything.
     */
    public function opensInNewTab(): bool
    {
        return $this->isClickable && $this->target === '_blank';
    }

    public function hasChildren(): bool
    {
        return !empty($this->children);
    }

    public function isActiveOrAncestor(): bool
    {
        return $this->isActive || $this->isActiveAncestor;
    }

    /**
     * The mobile-presentation accessors — derived reads over the single
     * stored `mobile` bag, in the same shape and for the same reason as
     * {@see iconClass()} and {@see badgeClass()}: the node is what gets
     * cached, so a rule tightened in a later release has to apply to trees
     * cached before it, and a value written straight into the database has
     * to read back as the default rather than reach a template.
     *
     * None of these know what a breakpoint is. `mobileVisibility()` says
     * which navigations an item belongs to; *when* a navigation is the
     * mobile one is your stylesheet's decision, or your template's when it
     * calls {@see MenuBuilderTree::forViewport()}.
     */
    public function mobileVisibility(): string
    {
        return MobileHelper::visibility($this->mobile['visibility'] ?? null);
    }

    /**
     * Whether this item belongs in the given viewport
     * (`MobileHelper::VIEWPORT_*`). An unknown viewport keeps the item —
     * see {@see MobileHelper::isVisibleOn()}.
     */
    public function isVisibleOn(string $viewport): bool
    {
        return MobileHelper::isVisibleOn($viewport, $this->mobile);
    }

    public function showsOnMobile(): bool
    {
        return $this->isVisibleOn(MobileHelper::VIEWPORT_MOBILE);
    }

    public function showsOnDesktop(): bool
    {
        return $this->isVisibleOn(MobileHelper::VIEWPORT_DESKTOP);
    }

    /**
     * The item's mobile sort override, or null when it has none.
     *
     * Data, never a CSS `order`: applied by re-sorting the tree in
     * {@see MenuBuilderTree::forViewport()}, so the DOM order and the
     * visual order stay the same thing. See the {@see MobileHelper} class
     * docblock for why the CSS route is a WCAG 1.3.2 / 2.4.3 failure.
     */
    public function mobileOrder(): ?int
    {
        return MobileHelper::order($this->mobile['order'] ?? null);
    }

    /**
     * Whether this node's children are a collapsed disclosure on mobile.
     *
     * Derived, with the editor's override on top: a branch is a disclosure
     * and a leaf is not, because a `<details>` around nothing is a control
     * that opens an empty panel. An editor who turns it off is saying "this
     * branch stays open on mobile", which is why
     * {@see MobileHelper::collapsible()} distinguishes stored `false` from
     * absence.
     */
    public function isMobileCollapsible(): bool
    {
        if (!$this->hasChildren()) {
            return false;
        }

        return MobileHelper::collapsible($this->mobile['collapsible'] ?? null) ?? true;
    }

    /** How this node's mega-menu panel behaves on mobile — one of `MobileHelper::MEGA_*`. */
    public function mobileMegaMenuBehavior(): string
    {
        return MobileHelper::megaMenuBehavior($this->mobile['megaMenu'] ?? null);
    }

    /**
     * The value for `data-mb-viewport`, or null when this item belongs to
     * both viewports and the attribute would say nothing. The whole of the
     * CSS contract — see {@see MobileHelper::viewportAttribute()}.
     */
    public function viewportAttribute(): ?string
    {
        return MobileHelper::viewportAttribute($this->mobile);
    }

    /**
     * Groups this node's already-resolved children by their
     * `megaMenuColumn` (1-based; anything unset or out of range collapses
     * into column 1) — pure grouping logic, no DB access, so it stays
     * testable and cacheable as part of the resolved node itself.
     *
     * @return array<int, MenuBuilderNode[]> Keyed by column number, ascending, only non-empty columns.
     */
    public function megaMenuColumns(): array
    {
        $columns = [];
        $columnCount = $this->megaMenu?->columns ?? 1;

        foreach ($this->children as $child) {
            $column = $child->megaMenuColumn;
            if ($column === null || $column < 1 || $column > $columnCount) {
                $column = 1;
            }
            $columns[$column][] = $child;
        }

        ksort($columns);

        return $columns;
    }
}

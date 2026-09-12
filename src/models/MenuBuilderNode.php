<?php

namespace Tahadudhiya\MenuBuilder\models;

use Tahadudhiya\MenuBuilder\helpers\BadgeHelper;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\helpers\MobileHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;

/**
 * The Twig-facing representation of a resolved navigation item — hides the database entirely (no
 * IDs to join, no parentId, no sort columns).
*/
class MenuBuilderNode
{
    /** The three derived readers over the stored `icon` column, plus `hasIcon()`. */
    use IconAccessors;

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
        /**
         * Mega-menu config for this node when it's the mega-menu parent, null otherwise — see
         * MenuBuilderItem::$metadata['megaMenu'].
        */
        public readonly ?MenuBuilderMegaMenuConfig $megaMenu = null,
        /**
         * Which column this node belongs to under its mega-menu-enabled parent; meaningless
         * otherwise.
        */
        public readonly ?int $megaMenuColumn = null,
        /**
         * True when this node was synthesized from a dynamic navigation source rather than a
         * persisted item — see MenuBuilderDynamicNavigationService.
        */
        public readonly bool $isDynamic = false,
        /**
         * The badge's style — one of BadgeHelper::STYLES or null for the default.
        */
        public readonly ?string $badgeStyle = null,
        /**
         * The `elements` row carrying this item's custom field content — a {@see
         * \Tahadudhiya\MenuBuilder\elements\MenuBuilderItemContent} — or null when the menu
         * defines no fields.
        */
        public readonly ?int $contentId = null,
        /**
         * The item's normalized mobile-presentation config — see {@see MobileHelper}.
         *
         * @var array{visibility?: string, order?: int, collapsible?: bool, megaMenu?: string}
        */
        public readonly array $mobile = [],
    ) {
    }

    /**
     * A copy of this node carrying the given (already-copied) children, with each child's `parent`
     * rewired to the copy.
     *
     * @param MenuBuilderNode[] $children
     * @param bool $preserveActiveState Keep this node's already-marked active state on the copy.
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

    /**
     * The badge's class list: the base class plus a `--<style>` modifier for a known style.
    */
    public function badgeClass(): string
    {
        return BadgeHelper::cssClass($this->badgeStyle);
    }

    /**
     * One custom field value by handle, or `$default` when this menu has no such field.
    */
    public function custom(string $handle, mixed $default = null): mixed
    {
        // Short-circuited rather than delegated: a node with no content has no fields by
        // definition, and this keeps a plain MenuBuilderNode readable without a booted plugin —
        // which is what lets the node's own behaviour be unit-tested without a database.
        return $this->contentId === null
            ? $default
            : MenuBuilder::getInstance()->itemContent->valueFor($this->contentId, $handle, $default);
    }

    /**
     * Whether this item has a non-empty value for the given custom field.
    */
    public function hasCustom(string $handle): bool
    {
        return $this->contentId !== null
            && MenuBuilder::getInstance()->itemContent->hasValueFor($this->contentId, $handle);
    }

    /**
     * Every custom field handle this node can answer to — what a template or a GraphQL query
     * iterates when it wants the menu's fields without naming them.
     *
     * @return string[]
    */
    public function customHandles(): array
    {
        return $this->contentId === null
            ? []
            : MenuBuilder::getInstance()->itemContent->handlesFor($this->contentId);
    }

    /**
     * The item's custom HTML attributes, re-checked at render time and stripped of anything unsafe
     * or reserved — see {@see LinkAttributeHelper::filterHtmlAttributes()}.
     *
     * @return array<string,string>
    */
    public function safeHtmlAttributes(): array
    {
        return LinkAttributeHelper::filterHtmlAttributes($this->htmlAttributes);
    }

    /**
     * Whether following this link leaves the current tab.
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
     * The mobile-presentation accessors — derived reads over the single stored `mobile` bag, in
     * the same shape and for the same reason as {@see iconClass()} and {@see badgeClass()}: the
     * node is what gets cached, so a rule tightened in a later release has to apply to trees cached
     * before it, and a value written straight into the database has to read back as the default
     * rather than reach a template.
    */
    public function mobileVisibility(): string
    {
        return MobileHelper::visibility($this->mobile['visibility'] ?? null);
    }

    /**
     * Whether this item belongs in the given viewport (`MobileHelper::VIEWPORT_*`).
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
    */
    public function mobileOrder(): ?int
    {
        return MobileHelper::order($this->mobile['order'] ?? null);
    }

    /**
     * Whether this node's children are a collapsed disclosure on mobile.
    */
    public function isMobileCollapsible(): bool
    {
        if (!$this->hasChildren()) {
            return false;
        }

        return MobileHelper::collapsible($this->mobile['collapsible'] ?? null) ?? true;
    }

    /**
     * How this node's mega-menu panel behaves on mobile — one of `MobileHelper::MEGA_*`.
    */
    public function mobileMegaMenuBehavior(): string
    {
        return MobileHelper::megaMenuBehavior($this->mobile['megaMenu'] ?? null);
    }

    /**
     * The value for `data-mb-viewport`, or null when this item belongs to both viewports and the
     * attribute would say nothing.
    */
    public function viewportAttribute(): ?string
    {
        return MobileHelper::viewportAttribute($this->mobile);
    }

    /**
     * Groups this node's already-resolved children by their `megaMenuColumn` (1-based; anything
     * unset or out of range collapses into column 1) — pure grouping logic, no DB access, so it
     * stays testable and cacheable as part of the resolved node itself.
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

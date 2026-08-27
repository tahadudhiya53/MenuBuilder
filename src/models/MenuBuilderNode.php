<?php

namespace Tahadudhiya\MenuBuilder\models;

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
     */
    public function withChildren(array $children): self
    {
        $copy = clone $this;
        $copy->children = $children;
        $copy->isActive = false;
        $copy->isActiveAncestor = false;

        foreach ($children as $child) {
            $child->parent = $copy;
        }

        return $copy;
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

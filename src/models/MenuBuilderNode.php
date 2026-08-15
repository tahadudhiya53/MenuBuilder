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
    ) {
    }

    public function hasChildren(): bool
    {
        return !empty($this->children);
    }

    public function isActiveOrAncestor(): bool
    {
        return $this->isActive || $this->isActiveAncestor;
    }
}

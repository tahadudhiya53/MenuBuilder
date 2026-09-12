<?php

namespace Tahadudhiya\MenuBuilder\models;

use Tahadudhiya\MenuBuilder\helpers\IconHelper;

/**
 * The read-only derived accessors over a model's single stored `icon`
 * string — see {@see IconHelper} for the grammar.
 *
 * Shared by {@see MenuBuilderItem} (the editable, persisted item) and
 * {@see MenuBuilderNode} (the resolved, cacheable Twig-facing node), which
 * both carry the same column and both have to answer the same three
 * questions about it. Written once so the CP row, the preview stage, the
 * bundled macro, the REST serializer and the GraphQL type cannot end up
 * reading an icon two different ways.
 *
 * Derived rather than resolved into stored state on purpose: the node is
 * what gets cached, and an icon's *rendering* (an asset's URL, above all)
 * can change without the item changing, so the tree caches the reference
 * and templates resolve it per request through
 * `craft.menuBuilder.iconAsset(node)`.
 *
 * {@see iconClass()} fails closed: a value that wouldn't validate today —
 * a legacy row, a direct database write — reads back as null rather than
 * reaching a template.
 */
trait IconAccessors
{
    /** `IconHelper::TYPE_CLASS` / `TYPE_ASSET`, or null when there is no usable icon. */
    public function iconType(): ?string
    {
        return IconHelper::type($this->icon);
    }

    /** The icon's class list, or null when the icon is empty, an asset, or (fail-closed) unsafe. */
    public function iconClass(): ?string
    {
        return IconHelper::classValue($this->icon);
    }

    /** The icon's asset id, or null when the icon is empty or a class. */
    public function iconAssetId(): ?int
    {
        return IconHelper::assetId($this->icon);
    }

    /** True when there is an icon to render at all, of either form. */
    public function hasIcon(): bool
    {
        return $this->iconType() !== null;
    }
}

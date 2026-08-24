<?php

namespace Tahadudhiya\MenuBuilder\models;

/**
 * Resolved, validated mega-menu configuration for one node — built from
 * `MenuBuilderItem::$metadata['megaMenu']` in MenuBuilderResolver. Never
 * persisted directly; the raw config lives in the item's `metadata` bag.
 */
final class MenuBuilderMegaMenuConfig
{
    public function __construct(
        public readonly int $columns,
    ) {
    }
}

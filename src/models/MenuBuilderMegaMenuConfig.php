<?php

namespace Tahadudhiya\MenuBuilder\models;

/**
 * Resolved, validated mega-menu configuration for one node — built from
 * `MenuBuilderItem::$metadata['megaMenu']` in MenuBuilderResolver.
*/
final class MenuBuilderMegaMenuConfig
{
    public function __construct(
        public readonly int $columns,
    ) {
    }
}

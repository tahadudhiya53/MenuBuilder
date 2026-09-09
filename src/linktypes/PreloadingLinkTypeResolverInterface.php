<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

/**
 * A link type whose resolution hits the database per item, and which can therefore be told up front
 * which elements the whole tree is about to ask for.
*/
interface PreloadingLinkTypeResolverInterface extends LinkTypeResolverInterface
{
    /**
     * Loads the given element IDs for the current site in as few queries as possible, so the
     * per-item `resolve()` calls that follow don't each issue one of their own.
     *
     * @param int[] $elementIds
    */
    public function preload(array $elementIds): void;

    /**
     * Drops whatever {@see preload()} gathered.
    */
    public function releasePreloaded(): void;
}

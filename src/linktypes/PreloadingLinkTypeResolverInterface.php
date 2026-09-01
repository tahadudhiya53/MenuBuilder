<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

/**
 * A link type whose resolution hits the database per item, and which can
 * therefore be told up front which elements the whole tree is about to ask
 * for.
 *
 * Deliberately a *separate* interface rather than a method on
 * {@see LinkTypeResolverInterface}: most link types resolve from the item's
 * own columns and have nothing to preload, and a third-party resolver
 * registered through `MenuBuilderLinkResolver::EVENT_REGISTER_LINK_TYPES`
 * must keep working untouched. Implementing this is an opt-in optimisation,
 * never a requirement — {@see MenuBuilderLinkResolver::preload()} simply
 * skips resolvers that don't.
 *
 * A preload is a *hint*, and the contract is that it changes nothing an
 * observer can see: `resolve()` must return exactly what it would have
 * returned without one, for the same item on the same site.
 */
interface PreloadingLinkTypeResolverInterface extends LinkTypeResolverInterface
{
    /**
     * Loads the given element IDs for the current site in as few queries as
     * possible, so the per-item `resolve()` calls that follow don't each
     * issue one of their own.
     *
     * @param int[] $elementIds
     */
    public function preload(array $elementIds): void;

    /**
     * Drops whatever {@see preload()} gathered.
     *
     * Called once the tree has been built, because the resolvers are
     * memoized for the lifetime of the request
     * ({@see MenuBuilderLinkResolver}) while the elements are wanted only
     * for the build — holding a thousand Craft elements past it would trade
     * the query count for a memory leak.
     */
    public function releasePreloaded(): void;
}

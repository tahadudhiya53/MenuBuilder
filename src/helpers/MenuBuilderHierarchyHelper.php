<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * The tree maths behind every hierarchy mutation, as pure functions over a
 * snapshot of one group's rows.
 *
 * There is exactly one hierarchy system in this plugin: adjacency rows in
 * `menubuilder_items` (`parentId` + `sortOrder`), assembled into a tree by
 * {@see \Tahadudhiya\MenuBuilder\services\MenuBuilderItemService::getTree()}.
 * This helper stores nothing and owns nothing — it exists so the rules that
 * protect those rows (no cycles, no cross-group parents, max depth,
 * deterministic sibling numbering) are computed from ONE snapshot query
 * instead of a query per level, and so they can be tested without a booted
 * Craft app.
 *
 * A "snapshot" is the group's rows as
 * `[['id' => int, 'parentId' => int|null, 'sortOrder' => int], …]`.
 * Everything here is cycle-guarded: rows written by an older version, edited
 * by hand, or left behind by two concurrent moves must make these functions
 * return a wrong-but-finite answer, never hang or blow the stack.
 */
class MenuBuilderHierarchyHelper
{
    /**
     * @param array<int,array{id:int|string,parentId:int|string|null,sortOrder:int|string}> $rows
     * @return array<int,int|null> id => parentId
     */
    public static function parentMap(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $map[(int)$row['id']] = $row['parentId'] === null ? null : (int)$row['parentId'];
        }

        return $map;
    }

    /**
     * Children of every parent, in canonical order: `sortOrder` first, then
     * `id` as the tiebreaker so two rows sharing a sortOrder (legacy data, a
     * half-applied concurrent write) still come back in the same order on
     * every request instead of whatever the database felt like returning.
     *
     * @param array<int,array{id:int|string,parentId:int|string|null,sortOrder:int|string}> $rows
     * @return array<int|string,int[]> parentId (0 for roots) => ordered child IDs
     */
    public static function childMap(array $rows): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $key = $row['parentId'] === null ? 0 : (int)$row['parentId'];
            $buckets[$key][] = [
                'id' => (int)$row['id'],
                'sortOrder' => (int)$row['sortOrder'],
            ];
        }

        $map = [];

        foreach ($buckets as $key => $children) {
            usort($children, fn(array $a, array $b) => [$a['sortOrder'], $a['id']] <=> [$b['sortOrder'], $b['id']]);
            $map[$key] = array_column($children, 'id');
        }

        return $map;
    }

    /**
     * The IDs in `$rows` whose parent chain is present in `$rows` all the way
     * up to a root.
     *
     * {@see \Tahadudhiya\MenuBuilder\services\MenuBuilderItemService::getTree()}
     * nests whatever rows the query returned and treats a row whose
     * `parentId` names something absent as a root. That is the right call for
     * a genuine orphan, and the wrong one for a row that was filtered out on
     * purpose: with disabled items excluded, a disabled parent's enabled
     * children would be promoted to the top level of the rendered menu —
     * items an editor believed they had switched off, reappearing somewhere
     * they were never placed. Disabling a parent therefore hides its whole
     * subtree, exactly the way a failed visibility rule does in
     * MenuBuilderResolver::filterVisible().
     *
     * Cycle-guarded, like everything else here: a row sitting inside a
     * corrupt ancestry loop never reaches a root, so it is reported
     * unreachable rather than hanging the walk. Same fail-closed direction —
     * anything this can't prove belongs in the tree stays out of it.
     *
     * @param array<int,array{id:int|string,parentId:int|string|null,sortOrder:int|string}> $rows
     * @return array<int,true> Keyed by ID, so callers can test membership in O(1).
     */
    public static function idsReachableFromRoots(array $rows): array
    {
        $parentMap = self::parentMap($rows);
        $reachable = [];

        foreach (array_keys($parentMap) as $id) {
            $chain = [];
            $seen = [];
            $walk = $id;

            while (true) {
                if (isset($reachable[$walk])) {
                    break;
                }

                // A parentId with no row behind it (a filtered-out parent, or
                // — impossible through this plugin's own writes — a parent in
                // another group) and a loop both mean "no root above here".
                if (isset($seen[$walk]) || !array_key_exists($walk, $parentMap)) {
                    $chain = [];

                    break;
                }

                $seen[$walk] = true;
                $chain[] = $walk;
                $parent = $parentMap[$walk];

                if ($parent === null) {
                    break;
                }

                $walk = $parent;
            }

            foreach ($chain as $chainId) {
                $reachable[$chainId] = true;
            }
        }

        return $reachable;
    }

    /**
     * The ordered IDs of the sibling set an item sits in. `null` means the
     * root set.
     *
     * @param array<int|string,int[]> $childMap
     * @return int[]
     */
    public static function siblingIds(array $childMap, ?int $parentId): array
    {
        return $childMap[$parentId ?? 0] ?? [];
    }

    /**
     * Ancestors of `$id`, nearest first. An unresolvable parentId simply
     * ends the walk — {@see getTree()} surfaces such a row as a root, so
     * depth is measured the same way here.
     *
     * @param array<int,int|null> $parentMap
     * @return int[]
     */
    public static function ancestorIds(array $parentMap, int $id): array
    {
        $ancestors = [];
        $seen = [$id => true];
        $walk = $parentMap[$id] ?? null;

        while ($walk !== null && array_key_exists($walk, $parentMap) && !isset($seen[$walk])) {
            $seen[$walk] = true;
            $ancestors[] = $walk;
            $walk = $parentMap[$walk] ?? null;
        }

        return $ancestors;
    }

    /**
     * Whether walking up from `$id` runs into a loop — i.e. the stored rows
     * are already corrupt, from a hand-edited row or from two moves that
     * each validated against the other's pre-commit state. Every depth
     * measurement above a cyclic ancestry is meaningless, so callers use
     * this to fail closed instead of nesting anything further into it.
     *
     * @param array<int,int|null> $parentMap
     */
    public static function ancestryIsCyclic(array $parentMap, int $id): bool
    {
        $seen = [$id => true];
        $walk = $parentMap[$id] ?? null;

        while ($walk !== null && array_key_exists($walk, $parentMap)) {
            if (isset($seen[$walk])) {
                return true;
            }

            $seen[$walk] = true;
            $walk = $parentMap[$walk] ?? null;
        }

        return false;
    }

    /**
     * Whether parenting `$itemId` to `$newParentId` closes a loop — the new
     * parent being the item itself, or sitting somewhere below it.
     *
     * @param array<int,int|null> $parentMap
     */
    public static function wouldCreateCycle(array $parentMap, int $itemId, ?int $newParentId): bool
    {
        if ($newParentId === null) {
            return false;
        }

        if ($newParentId === $itemId) {
            return true;
        }

        return in_array($itemId, self::ancestorIds($parentMap, $newParentId), true);
    }

    /**
     * Extra levels below `$itemId` — 0 for a leaf, 1 when it has children,
     * and so on.
     *
     * @param array<int|string,int[]> $childMap
     * @param array<int,true> $seen
     */
    public static function subtreeHeight(array $childMap, int $itemId, array $seen = []): int
    {
        // 0 is `childMap`'s key for the root set, never a real item ID, so
        // "the height below item 0" is not a question about any item — see
        // {@see deepestLevelAfterMove()}, which is where asking it by
        // accident used to reject legitimate inserts.
        if ($itemId === 0 || isset($seen[$itemId])) {
            return 0;
        }

        $seen[$itemId] = true;
        $height = 0;

        foreach ($childMap[$itemId] ?? [] as $childId) {
            $height = max($height, 1 + self::subtreeHeight($childMap, $childId, $seen));
        }

        return $height;
    }

    /**
     * Every descendant of `$itemId`, breadth-first.
     *
     * @param array<int|string,int[]> $childMap
     * @return int[]
     */
    public static function descendantIds(array $childMap, int $itemId): array
    {
        $descendants = [];
        $seen = [$itemId => true];
        $queue = $childMap[$itemId] ?? [];

        while ($queue) {
            $id = array_shift($queue);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $descendants[] = $id;

            foreach ($childMap[$id] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return $descendants;
    }

    /**
     * The 1-based level the DEEPEST row of `$itemId`'s subtree would land on
     * after the move — what max depth has to be measured against, because a
     * move carries the item's descendants with it. A leaf moved to the root
     * is level 1; the same item with grandchildren is level 3.
     *
     * `$itemId` is null for an item that doesn't exist yet (an insert): it
     * has no descendants to carry, so only the parent's level counts. It
     * must NOT be passed as `0` for that case — 0 is `childMap`'s key for
     * the **root set** (see {@see childMap()}), so a subtree height read
     * under it is the height of the whole root forest, and an insert would
     * be measured against unrelated branches.
     *
     * @param array<int,int|null> $parentMap
     * @param array<int|string,int[]> $childMap
     */
    public static function deepestLevelAfterMove(array $parentMap, array $childMap, ?int $itemId, ?int $newParentId): int
    {
        $parentLevel = $newParentId === null ? 0 : count(self::ancestorIds($parentMap, $newParentId)) + 1;

        return $parentLevel + 1 + ($itemId === null ? 0 : self::subtreeHeight($childMap, $itemId));
    }

    /**
     * Turns the client's requested sibling order into the order actually
     * written, reconciled against what the set really contains right now.
     *
     * The posted order is a snapshot of the editor's screen and can be stale
     * — another editor (or another tab) may have added, deleted or moved
     * something in the same set since the page was rendered. So it is
     * treated as a preference, never as the truth:
     *
     * - IDs that are no longer in the set are dropped (never resurrected);
     * - IDs the client never knew about keep their relative order and follow
     *   the requested ones, rather than vanishing;
     * - the moved item is honoured at `$fallbackIndex` if the client didn't
     *   name it at all.
     *
     * The result is always a permutation of `$currentIds`, which is what
     * keeps the renumbering that follows total and gap-free.
     *
     * @param int[] $currentIds The set's real membership, in canonical order.
     * @param int[] $requestedIds The client's desired order (may be stale/partial/foreign).
     * @return int[]
     */
    public static function resolveSiblingOrder(array $currentIds, array $requestedIds, ?int $movedId = null, int $fallbackIndex = 0): array
    {
        $current = array_values(array_unique(array_map('intval', $currentIds)));
        $membership = array_flip($current);

        $ordered = [];
        $placed = [];

        foreach ($requestedIds as $requestedId) {
            $requestedId = (int)$requestedId;

            if (!isset($membership[$requestedId]) || isset($placed[$requestedId])) {
                continue;
            }

            $placed[$requestedId] = true;
            $ordered[] = $requestedId;
        }

        foreach ($current as $id) {
            if (!isset($placed[$id]) && $id !== $movedId) {
                $placed[$id] = true;
                $ordered[] = $id;
            }
        }

        if ($movedId !== null && isset($membership[$movedId]) && !isset($placed[$movedId])) {
            $index = max(0, min($fallbackIndex, count($ordered)));
            array_splice($ordered, $index, 0, [$movedId]);
        }

        return $ordered;
    }

    /**
     * The complete set of writes one move needs, computed from a snapshot
     * taken before it: the item's new `parentId`, plus every `sortOrder`
     * that has to change as a result — in the destination set (where the
     * item now sits) and in the set it left (which closes up behind it).
     *
     * Descendants never appear in the plan, and that is the point: they
     * point at their parent by id, so the whole subtree travels with one
     * row's `parentId`. Nothing below the moved item is rewritten, so
     * nothing below it can be lost or reordered by the move.
     *
     * Pure, so the ordering rules can be exercised for real without a
     * database: {@see \Tahadudhiya\MenuBuilder\services\MenuBuilderItemService::move()}
     * only validates, locks, and executes what this returns.
     *
     * @param array<int,array{id:int|string,parentId:int|string|null,sortOrder:int|string}> $rows The group as it is now.
     * @param int[] $requestedSiblingIds The client's desired destination order (may be stale).
     * @return array{parentId:int|null,sortOrders:array<int,int>}
     */
    public static function planMove(array $rows, int $itemId, ?int $newParentId, int $newSortOrder, array $requestedSiblingIds = []): array
    {
        $oldParentId = self::parentMap($rows)[$itemId] ?? null;

        $moved = array_map(function(array $row) use ($itemId, $newParentId) {
            if ((int)$row['id'] === $itemId) {
                $row['parentId'] = $newParentId;
            }

            return $row;
        }, $rows);

        $childMap = self::childMap($moved);
        $sortOrders = [];

        foreach ($rows as $row) {
            $sortOrders[(int)$row['id']] = (int)$row['sortOrder'];
        }

        $destination = self::resolveSiblingOrder(
            self::siblingIds($childMap, $newParentId),
            $requestedSiblingIds,
            $itemId,
            $newSortOrder
        );
        $assignments = self::sortOrderAssignments($destination, $sortOrders);

        // The set the item left keeps a hole where it used to sit. Ordering
        // survives a gap, but compacting keeps sortOrder meaning "index in
        // the sibling set" everywhere, which is what makes a position posted
        // by one request comparable with one posted by the next.
        if ($oldParentId !== $newParentId) {
            $assignments += self::sortOrderAssignments(
                self::siblingIds($childMap, $oldParentId),
                $sortOrders
            );
        }

        return ['parentId' => $newParentId, 'sortOrders' => $assignments];
    }

    /**
     * The `sortOrder` writes needed to make `$orderedIds` canonical: index
     * position for each row whose stored value doesn't already match.
     *
     * Returning only the differences is what keeps a drag inside a 500-item
     * root set from rewriting all 500 rows — and, with it, what keeps two
     * editors working in different corners of the same menu from colliding
     * on rows neither of them touched.
     *
     * @param int[] $orderedIds
     * @param array<int,int> $currentSortOrders id => stored sortOrder
     * @return array<int,int> id => new sortOrder
     */
    public static function sortOrderAssignments(array $orderedIds, array $currentSortOrders): array
    {
        $assignments = [];

        foreach (array_values($orderedIds) as $index => $id) {
            if (($currentSortOrders[$id] ?? null) !== $index) {
                $assignments[$id] = $index;
            }
        }

        return $assignments;
    }
}

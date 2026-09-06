<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * The tree maths behind every hierarchy mutation, as pure functions over a snapshot of one group's
 * rows.
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
     * Children of every parent, in canonical order: `sortOrder` first, then `id` as the tiebreaker
     * so two rows sharing a sortOrder (legacy data, a half-applied concurrent write) still come
     * back in the same order on every request instead of whatever the database felt like returning.
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
     * The IDs in `$rows` whose parent chain is present in `$rows` all the way up to a root.
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

                // A parentId with no row behind it (a filtered-out parent, or — impossible
                // through this plugin's own writes — a parent in another group) and a loop both
                // mean "no root above here".
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
     * The ordered IDs of the sibling set an item sits in.
     *
     * @param array<int|string,int[]> $childMap
     * @return int[]
    */
    public static function siblingIds(array $childMap, ?int $parentId): array
    {
        return $childMap[$parentId ?? 0] ?? [];
    }

    /**
     * Ancestors of `$id`, nearest first.
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
     * Whether walking up from `$id` runs into a loop — i.e.
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
     * Whether parenting `$itemId` to `$newParentId` closes a loop — the new parent being the item
     * itself, or sitting somewhere below it.
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
     * Extra levels below `$itemId` — 0 for a leaf, 1 when it has children, and so on.
     *
     * @param array<int|string,int[]> $childMap
     * @param array<int,true> $seen
    */
    public static function subtreeHeight(array $childMap, int $itemId, array $seen = []): int
    {
        // 0 is `childMap`'s key for the root set, never a real item ID, so "the height below item
        // 0" is not a question about any item — see {@see deepestLevelAfterMove()}, which is
        // where asking it by accident used to reject legitimate inserts.
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
     * The 1-based level the DEEPEST row of `$itemId`'s subtree would land on after the move —
     * what max depth has to be measured against, because a move carries the item's descendants with
     * it.
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
     * Turns the client's requested sibling order into the order actually written, reconciled
     * against what the set really contains right now.
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
     * The complete set of writes one move needs, computed from a snapshot taken before it: the
     * item's new `parentId`, plus every `sortOrder` that has to change as a result — in the
     * destination set (where the item now sits) and in the set it left (which closes up behind it).
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

        // The set the item left keeps a hole where it used to sit.
        if ($oldParentId !== $newParentId) {
            $assignments += self::sortOrderAssignments(
                self::siblingIds($childMap, $oldParentId),
                $sortOrders
            );
        }

        return ['parentId' => $newParentId, 'sortOrders' => $assignments];
    }

    /**
     * The `sortOrder` writes needed to make `$orderedIds` canonical: index position for each row
     * whose stored value doesn't already match.
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

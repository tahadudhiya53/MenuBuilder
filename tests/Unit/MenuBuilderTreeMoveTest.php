<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderHierarchyHelper as Hierarchy;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\services\MenuBuilderItemService;

/**
 * Drag-and-drop hierarchy and ordering.
 *
 * These are real behaviour tests, not source inspection: every decision a
 * move makes lives in {@see Hierarchy}, which is pure, so a move can be
 * applied to an in-memory group and the resulting tree asserted exactly as
 * the CP would render it. `applyMove()` below is the whole of what
 * MenuBuilderItemService::move() does to the data — validate, plan, write
 * `parentId`, write the planned `sortOrder`s — so what passes here is what
 * the service writes.
 *
 * The structural tests at the bottom cover the parts that can't be exercised
 * without a database: that the write path is one locked transaction, and
 * that the endpoint reaches it exactly once.
 *
 * @see MenuBuilderItemLifecycleTest for item CRUD and tree assembly.
 */
class MenuBuilderTreeMoveTest extends TestCase
{
    // ---------------------------------------------------------------------
    // The six cases
    // ---------------------------------------------------------------------

    /** CASE 1 — reorder siblings: A B C D, move D before A. */
    public function testMovingASiblingToTheTopReordersTheRestDownwards(): void
    {
        $rows = $this->rows(['A' => null, 'B' => null, 'C' => null, 'D' => null]);

        $rows = $this->applyMove($rows, 'D', null, 0);

        $this->assertSame(['D', 'A', 'B', 'C'], $this->outline($rows));
    }

    public function testMovingASiblingToTheEndReordersTheRestUpwards(): void
    {
        $rows = $this->rows(['A' => null, 'B' => null, 'C' => null, 'D' => null]);

        $rows = $this->applyMove($rows, 'A', null, 3);

        $this->assertSame(['B', 'C', 'D', 'A'], $this->outline($rows));
    }

    /** CASE 2 — nest under a sibling: A B C, move C under A. */
    public function testMovingAnItemUnderAnotherItemNestsItAndClosesTheGapBehindIt(): void
    {
        $rows = $this->rows(['A' => null, 'B' => null, 'C' => null]);

        $rows = $this->applyMove($rows, 'C', 'A', 0);

        $this->assertSame(['A', '  C', 'B'], $this->outline($rows));
        // The root set it left is compacted, not left with a hole at index 2.
        $this->assertSame([0, 1], [$this->sortOrderOf($rows, 'A'), $this->sortOrderOf($rows, 'B')]);
    }

    public function testMovingAnItemToTheRootUnnestsIt(): void
    {
        $rows = $this->rows(['A' => null, 'C' => 'A', 'B' => null]);

        $rows = $this->applyMove($rows, 'C', null, 2);

        $this->assertSame(['A', 'B', 'C'], $this->outline($rows));
    }

    /** CASE 3 — A > B > C, move A under C. Must fail. */
    public function testMovingAnItemUnderItsOwnDescendantIsRejected(): void
    {
        $rows = $this->rows(['A' => null, 'B' => 'A', 'C' => 'B']);
        $parentMap = Hierarchy::parentMap($rows);

        $this->assertTrue(Hierarchy::wouldCreateCycle($parentMap, $this->id('A'), $this->id('C')));
        $this->assertTrue(Hierarchy::wouldCreateCycle($parentMap, $this->id('A'), $this->id('B')));
        $this->assertTrue(Hierarchy::wouldCreateCycle($parentMap, $this->id('A'), $this->id('A')), 'An item is never its own parent.');

        // The legal direction of the same relationship stays legal.
        $this->assertFalse(Hierarchy::wouldCreateCycle($parentMap, $this->id('C'), null));
        $this->assertFalse(Hierarchy::wouldCreateCycle($parentMap, $this->id('B'), null));
    }

    /**
     * A cycle already sitting in the rows — two moves that each validated
     * against the other's pre-commit state before the group lock existed, or
     * a row edited straight in the database — must make the walks return a
     * finite wrong answer and the checks fail closed, never hang.
     */
    public function testAPreExistingCycleInTheStoredRowsIsDetectedRatherThanFollowedForever(): void
    {
        $cyclic = [
            ['id' => 1, 'parentId' => 2, 'sortOrder' => 0],
            ['id' => 2, 'parentId' => 1, 'sortOrder' => 0],
            ['id' => 3, 'parentId' => null, 'sortOrder' => 0],
        ];
        $parentMap = Hierarchy::parentMap($cyclic);

        $this->assertTrue(Hierarchy::ancestryIsCyclic($parentMap, 1));
        $this->assertTrue(Hierarchy::ancestryIsCyclic($parentMap, 2));
        $this->assertFalse(Hierarchy::ancestryIsCyclic($parentMap, 3));
        $this->assertSame([2], Hierarchy::ancestorIds($parentMap, 1), 'The walk stops at the repeat.');
        // Finite, not meaningful: there is no true height for a loop. The
        // point is that the walk terminates so validateHierarchy() can
        // reject the move rather than the request dying on a blown stack.
        $this->assertSame(2, Hierarchy::subtreeHeight(Hierarchy::childMap($cyclic), 1));
        $this->assertSame([2], Hierarchy::descendantIds(Hierarchy::childMap($cyclic), 1));
    }

    /** CASE 4 — maxDepth 2: nothing may land on level 3. */
    public function testMaxDepthIsMeasuredAgainstTheDeepestRowOfTheMovingSubtree(): void
    {
        $group = new MenuBuilderGroup();
        $group->maxDepth = 2;

        $rows = $this->rows(['A' => null, 'B' => 'A', 'D' => null, 'E' => 'D']);
        $parentMap = Hierarchy::parentMap($rows);
        $childMap = Hierarchy::childMap($rows);

        // A leaf under a top-level item is level 2 — allowed.
        $level = Hierarchy::deepestLevelAfterMove($parentMap, $childMap, $this->id('E'), $this->id('A'));
        $this->assertSame(2, $level);
        $this->assertTrue($group->allowsDepth($level));

        // The same leaf under a level-2 item is level 3 — refused.
        $level = Hierarchy::deepestLevelAfterMove($parentMap, $childMap, $this->id('E'), $this->id('B'));
        $this->assertSame(3, $level);
        $this->assertFalse($group->allowsDepth($level));

        // And a PARENT under a top-level item is level 3 too, because its
        // child travels with it — the case a check on the moving item's own
        // level alone would wave through.
        $level = Hierarchy::deepestLevelAfterMove($parentMap, $childMap, $this->id('D'), $this->id('A'));
        $this->assertSame(3, $level);
        $this->assertFalse($group->allowsDepth($level));
    }

    /**
     * Max depth applies to a move to the ROOT as well: a three-level subtree
     * lifted to the top of a two-level menu still busts the limit. Skipping
     * the check whenever parentId was null let exactly that through.
     */
    public function testMaxDepthIsEnforcedForAMoveToTheRootToo(): void
    {
        $group = new MenuBuilderGroup();
        $group->maxDepth = 2;

        $rows = $this->rows(['A' => null, 'B' => 'A', 'C' => 'B', 'D' => null]);
        $parentMap = Hierarchy::parentMap($rows);
        $childMap = Hierarchy::childMap($rows);

        $this->assertSame(
            3,
            Hierarchy::deepestLevelAfterMove($parentMap, $childMap, $this->id('A'), null),
            'A + B + C at the root is still three levels deep.'
        );
        $this->assertFalse($group->allowsDepth(3));

        // Its two-level tail is fine at the root.
        $this->assertSame(2, Hierarchy::deepestLevelAfterMove($parentMap, $childMap, $this->id('B'), null));
        $this->assertTrue($group->allowsDepth(2));
        // And a leaf is always fine.
        $this->assertSame(1, Hierarchy::deepestLevelAfterMove($parentMap, $childMap, $this->id('D'), null));
    }

    public function testAGroupWithNoMaximumDepthAllowsAnyLevel(): void
    {
        $group = new MenuBuilderGroup();

        $this->assertTrue($group->allowsDepth(1));
        $this->assertTrue($group->allowsDepth(50));
    }

    /** CASE 5 — move subtree: A > B > C moved under D, all of it travels. */
    public function testMovingAnItemCarriesItsEntireSubtreeWithIt(): void
    {
        $rows = $this->rows(['A' => null, 'B' => 'A', 'C' => 'B', 'D' => null]);

        $rows = $this->applyMove($rows, 'A', 'D', 0);

        $this->assertSame(['D', '  A', '    B', '      C'], $this->outline($rows));
    }

    /**
     * Nothing below the moved item may be rewritten by the move: descendants
     * reference their parent by id, so touching them is how a subtree gets
     * flattened or loses its internal order.
     */
    public function testAMovePlanNeverRewritesTheMovedItemsDescendants(): void
    {
        $rows = $this->rows(['A' => null, 'B' => 'A', 'C' => 'B', 'D' => null]);

        $plan = Hierarchy::planMove($rows, $this->id('A'), $this->id('D'), 0);

        $this->assertSame($this->id('D'), $plan['parentId'] === null ? null : $plan['parentId']);
        foreach (['B', 'C'] as $descendant) {
            $this->assertArrayNotHasKey($this->id($descendant), $plan['sortOrders']);
        }
    }

    public function testMovingASubtreePreservesTheOrderOfItsChildren(): void
    {
        $rows = $this->rows(['A' => null, 'A1' => 'A', 'A2' => 'A', 'A3' => 'A', 'D' => null]);

        $rows = $this->applyMove($rows, 'A', 'D', 0);

        $this->assertSame(['D', '  A', '    A1', '    A2', '    A3'], $this->outline($rows));
    }

    /**
     * CASE 6 — an item's group is fixed at creation, so a move can never
     * change it. The service refuses the group change itself, and the
     * endpoint refuses a payload whose groupId disagrees with the item's own
     * rather than reordering against the wrong menu's sibling set.
     */
    public function testAnItemCanNeverBeMovedIntoAnotherGroup(): void
    {
        $this->assertFalse(MenuBuilderItemService::isGroupChangeAllowed(7, 8));
        $this->assertTrue(MenuBuilderItemService::isGroupChangeAllowed(7, 7));

        $reorder = $this->methodSource(ItemsController::class, 'actionReorder');

        $this->assertStringContainsString('$item->groupId !== $groupId', $reorder);
        $this->assertStringContainsString('cannot be moved to a different navigation group', $reorder);

        // And the parent named by a move must live in the same group.
        $this->assertStringContainsString(
            "(int)\$parent->groupId !== \$item->groupId",
            $this->methodSource(MenuBuilderItemService::class, 'validateHierarchy')
        );
    }

    /**
     * A group's snapshot is queried by groupId, so a plan can only ever
     * renumber rows inside that one group — an id from another menu posted
     * in `siblingIds` isn't in the set and is dropped.
     */
    public function testASiblingOrderFromAnotherGroupCannotReachThisGroupsRows(): void
    {
        $rows = $this->rows(['A' => null, 'B' => null]);
        $foreignId = 9999;

        $plan = Hierarchy::planMove($rows, $this->id('B'), null, 0, [$foreignId, $this->id('B'), $this->id('A')]);

        $this->assertArrayNotHasKey($foreignId, $plan['sortOrders']);
        $this->assertSame([$this->id('B') => 0, $this->id('A') => 1], $plan['sortOrders']);
    }

    // ---------------------------------------------------------------------
    // Stale / concurrent ordering
    // ---------------------------------------------------------------------

    /**
     * The posted order is a snapshot of one editor's screen. By the time it
     * arrives, another editor may have deleted a row, added one, or moved
     * one out of the set. It is therefore reconciled against the set's real
     * membership rather than written as given — the result is always a
     * permutation of what's actually there, which is what keeps the
     * renumbering gap-free.
     */
    public function testAStaleSiblingOrderCannotResurrectADeletedRow(): void
    {
        $current = [1, 2, 3];

        $this->assertSame([3, 1, 2], Hierarchy::resolveSiblingOrder($current, [3, 1, 99, 2]));
    }

    public function testASiblingOrderThatNeverSawANewRowKeepsThatRowRatherThanDroppingIt(): void
    {
        // 4 was added by another editor after this client rendered its page.
        $this->assertSame([3, 1, 2, 4], Hierarchy::resolveSiblingOrder([1, 2, 3, 4], [3, 1, 2]));
    }

    public function testADuplicatedIdInAPostedOrderIsHonouredOnce(): void
    {
        $this->assertSame([2, 1, 3], Hierarchy::resolveSiblingOrder([1, 2, 3], [2, 2, 1, 3]));
    }

    /**
     * With no usable posted order at all — a keyboard move, a scripted call,
     * or a payload the reconciliation emptied — the requested index still
     * decides where the item lands.
     */
    public function testTheRequestedIndexPlacesTheItemWhenThePostedOrderDoesNotNameIt(): void
    {
        $this->assertSame([4, 1, 2, 3], Hierarchy::resolveSiblingOrder([1, 2, 3, 4], [], 4, 0));
        $this->assertSame([1, 4, 2, 3], Hierarchy::resolveSiblingOrder([1, 2, 3, 4], [], 4, 1));
        $this->assertSame([1, 2, 3, 4], Hierarchy::resolveSiblingOrder([1, 2, 3, 4], [], 4, 3));
        // Out-of-range indexes clamp rather than dropping the item.
        $this->assertSame([1, 2, 3, 4], Hierarchy::resolveSiblingOrder([1, 2, 3, 4], [], 4, 99));
        $this->assertSame([4, 1, 2, 3], Hierarchy::resolveSiblingOrder([1, 2, 3, 4], [], 4, -5));
    }

    /**
     * Only rows whose position actually changes are written. That is what
     * keeps a drag inside a large sibling set from rewriting every row in it
     * — and with it, what keeps two editors working in different corners of
     * the same menu from overwriting rows neither of them touched.
     */
    public function testOnlyTheRowsWhosePositionChangedAreWritten(): void
    {
        $current = [10 => 0, 11 => 1, 12 => 2, 13 => 3];

        $this->assertSame([], Hierarchy::sortOrderAssignments([10, 11, 12, 13], $current));
        $this->assertSame([12 => 2, 13 => 3], Hierarchy::sortOrderAssignments([10, 11, 12, 13], [10 => 0, 11 => 1, 12 => 7, 13 => 9]));
        $this->assertSame([13 => 0, 10 => 1, 11 => 2, 12 => 3], Hierarchy::sortOrderAssignments([13, 10, 11, 12], $current));
    }

    /**
     * Two moves applied one after the other — which is what the group lock
     * forces concurrent drags to do — leave a set that is still a complete,
     * gap-free 0..n-1 sequence.
     */
    public function testSequentialMovesLeaveTheSiblingSetContiguous(): void
    {
        $rows = $this->rows(['A' => null, 'B' => null, 'C' => null, 'D' => null]);

        $rows = $this->applyMove($rows, 'D', null, 0);
        $rows = $this->applyMove($rows, 'C', null, 0);
        $rows = $this->applyMove($rows, 'B', 'C', 0);

        $this->assertSame(['C', '  B', 'D', 'A'], $this->outline($rows));
        $this->assertSame([0, 1, 2], $this->sortOrdersOfSiblings($rows, null));
    }

    /**
     * The second of two concurrent drags posts an order built before the
     * first one committed. It must still land the item where the editor
     * dropped it, and must not corrupt the set around it.
     */
    public function testAMovePostedAgainstAStaleSiblingOrderStillLandsCorrectly(): void
    {
        $rows = $this->rows(['A' => null, 'B' => null, 'C' => null, 'D' => null]);
        $staleOrder = [$this->id('A'), $this->id('B'), $this->id('C'), $this->id('D')];

        // Editor one moves D to the top and commits.
        $rows = $this->applyMove($rows, 'D', null, 0);
        // Editor two, still looking at the old order, drops B at index 1.
        $rows = $this->applyMove($rows, 'B', null, 1, $staleOrder);

        $this->assertSame([0, 1, 2, 3], $this->sortOrdersOfSiblings($rows, null));
        $this->assertCount(4, $this->outline($rows), 'No row was lost or duplicated.');
        $this->assertContains('B', $this->outline($rows));
    }

    // ---------------------------------------------------------------------
    // Scale
    // ---------------------------------------------------------------------

    /**
     * @dataProvider treeSizeProvider
     */
    public function testAFlatMenuStaysContiguousWhenTheLastItemIsDraggedToTheTop(int $size): void
    {
        $rows = $this->flatRows($size);
        $lastId = $size;

        $plan = Hierarchy::planMove($rows, $lastId, null, 0);
        $rows = $this->apply($rows, $lastId, null, $plan);

        $order = Hierarchy::siblingIds(Hierarchy::childMap($rows), null);

        $this->assertCount($size, $order);
        $this->assertSame($lastId, $order[0]);
        $this->assertSame(range(0, $size - 1), $this->sortOrdersOfSiblings($rows, null));
    }

    /**
     * A drag near the end of a large set must not rewrite the whole set —
     * only the rows between the old and new position actually shift.
     *
     * @dataProvider treeSizeProvider
     */
    public function testAMoveOnlyRewritesTheRowsItActuallyShifts(int $size): void
    {
        $rows = $this->flatRows($size);

        // Swap the last two rows: exactly two positions change.
        $plan = Hierarchy::planMove($rows, $size, null, $size - 2);

        $this->assertCount(2, $plan['sortOrders']);
    }

    /**
     * Depth measurement over a deep chain must stay finite and correct at
     * scale — this is the number max depth is checked against on every move.
     *
     * @dataProvider treeSizeProvider
     */
    public function testDepthAndSubtreeMathHoldOnADeeplyNestedMenu(int $size): void
    {
        $rows = [];
        for ($id = 1; $id <= $size; $id++) {
            $rows[] = ['id' => $id, 'parentId' => $id === 1 ? null : $id - 1, 'sortOrder' => 0];
        }

        $parentMap = Hierarchy::parentMap($rows);
        $childMap = Hierarchy::childMap($rows);

        $this->assertSame($size - 1, Hierarchy::subtreeHeight($childMap, 1));
        $this->assertCount($size - 1, Hierarchy::descendantIds($childMap, 1));
        $this->assertCount($size - 1, Hierarchy::ancestorIds($parentMap, $size));
        $this->assertSame($size, Hierarchy::deepestLevelAfterMove($parentMap, $childMap, 1, null));
        $this->assertTrue(Hierarchy::wouldCreateCycle($parentMap, 1, $size), 'The root may not move under its own deepest descendant.');
    }

    /** @return array<string,array{int}> */
    public static function treeSizeProvider(): array
    {
        return ['10 items' => [10], '100 items' => [100], '500 items' => [500]];
    }

    // ---------------------------------------------------------------------
    // Transactions, locking, and the endpoint
    // ---------------------------------------------------------------------

    /**
     * A move is a reparent plus two renumberings. Committing any of that
     * without the rest leaves a tree neither editor asked for, so it is one
     * transaction — and the endpoint makes exactly one service call, rather
     * than a move followed by a separate reorder that could fail on its own.
     */
    public function testAMoveIsASingleTransactionalServiceCall(): void
    {
        $move = $this->methodSource(MenuBuilderItemService::class, 'move');

        $this->assertStringContainsString('beginTransaction', $move);
        $this->assertStringContainsString('rollBack', $move);
        $this->assertStringContainsString('commit', $move);

        $reorder = $this->methodSource(ItemsController::class, 'actionReorder');

        $this->assertSame(1, substr_count($reorder, '$itemsService->move('));
        $this->assertStringNotContainsString('reorderSiblings(', $reorder);
    }

    /**
     * Validation that runs before the transaction only ever proves a move
     * was legal against a state that may already be gone. Two concurrent
     * drags in one menu are enough to build a cycle out of two individually
     * valid moves, so every hierarchy mutation takes a row lock on the group
     * first and re-reads inside it.
     */
    public function testConcurrentMovesInTheSameGroupAreSerialisedByARowLock(): void
    {
        $lock = $this->methodSource(MenuBuilderItemService::class, 'lockGroup');

        $this->assertStringContainsString('FOR UPDATE', $lock);
        $this->assertStringContainsString('getTransaction() === null', $lock, 'A lock outside a transaction would be released immediately.');

        // Every path that can change where a row sits, not just the drag
        // endpoint: the edit form's parent picker and a keep-children delete
        // reparent rows too, and can race a drag just as easily.
        foreach (['move', 'reorderSiblings', 'save', 'deleteById'] as $method) {
            $this->assertStringContainsString(
                '$this->lockGroup(',
                $this->methodSource(MenuBuilderItemService::class, $method),
                "$method() mutates the hierarchy and must serialise against concurrent mutations."
            );
        }

        // A field-only edit changes no structure and must not pay for a lock.
        $this->assertStringContainsString(
            '$needsLock = $isNew || $isReparent;',
            $this->methodSource(MenuBuilderItemService::class, 'save')
        );

        $move = $this->methodSource(MenuBuilderItemService::class, 'move');

        $this->assertLessThan(
            strpos($move, 'validateHierarchy($item)'),
            strpos($move, '$this->lockGroup('),
            'The row must be re-read and re-validated inside the lock, not before it.'
        );
        $this->assertLessThan(
            strpos($move, 'planMove('),
            strpos($move, 'validateHierarchy($item)'),
            'Nothing may be planned or written before the move is known to be legal.'
        );
    }

    /** Every write path still invalidates the group's cached render. */
    public function testMovingInvalidatesTheGroupCache(): void
    {
        foreach (['move', 'reorderSiblings'] as $method) {
            $this->assertStringContainsString(
                'invalidateGroup',
                $this->methodSource(MenuBuilderItemService::class, $method)
            );
        }
    }

    /**
     * A refused move must tell the editor which rule it broke — "that move
     * exceeds this group's maximum nesting depth" rather than a generic
     * failure — because the CP's only recovery from a rejection is a reload,
     * and a reload with no explanation looks like the drag simply didn't
     * take.
     */
    public function testARefusedMoveReportsWhichRuleItBroke(): void
    {
        $this->assertStringContainsString(
            "getFirstError('parentId')",
            $this->methodSource(MenuBuilderItemService::class, 'move')
        );
        $this->assertStringContainsString(
            'getLastMoveError()',
            $this->methodSource(ItemsController::class, 'actionReorder')
        );
    }

    /**
     * The endpoint is a mutation, and Craft only enforces CSRF on POST.
     * Permission is `edit`, since a move only ever acts on an item that
     * already exists.
     */
    public function testTheReorderEndpointIsPostOnlyAndPermissionGated(): void
    {
        $this->assertStringContainsString(
            'requirePostRequest()',
            $this->methodSource(ItemsController::class, 'actionReorder')
        );
        $this->assertSame(
            'menuBuilder:edit',
            ItemsController::requiredPermissionForAction('reorder', false)
        );
    }

    /**
     * Repositioning inside a large sibling set must not become one UPDATE
     * per row inside a held lock — that is how a 500-item menu turns a drag
     * into a lock-held stampede.
     */
    public function testSortOrdersAreWrittenInBatchesRatherThanRowByRow(): void
    {
        $source = $this->methodSource(MenuBuilderItemService::class, 'applySortOrders');

        $this->assertStringContainsString('CASE [[id]]', $source);
        $this->assertStringContainsString('array_chunk(', $source);
    }

    /**
     * The tree is read with one flat query per group; a per-node query would
     * turn a 500-item menu into 500 round trips on every CP page load.
     */
    public function testTheHierarchyIsReadWithASingleQueryPerGroup(): void
    {
        $snapshot = $this->methodSource(MenuBuilderItemService::class, 'snapshotForGroup');

        $this->assertSame(1, substr_count($snapshot, '::find()'));
        $this->assertStringContainsString("['id', 'parentId', 'sortOrder']", $snapshot);
        $this->assertSame(1, substr_count($this->methodSource(MenuBuilderItemService::class, 'getFlatForGroup'), '::find()'));
    }

    /**
     * Ordering must never depend on what the database felt like returning:
     * rows left sharing a sortOrder (legacy data, a hand-edited row) are
     * broken by id, at every level.
     */
    public function testSiblingOrderIsDeterministicWhenTwoRowsShareASortOrder(): void
    {
        $rows = [
            ['id' => 30, 'parentId' => null, 'sortOrder' => 1],
            ['id' => 10, 'parentId' => null, 'sortOrder' => 1],
            ['id' => 20, 'parentId' => null, 'sortOrder' => 0],
        ];

        $this->assertSame([20, 10, 30], Hierarchy::siblingIds(Hierarchy::childMap($rows), null));
        $this->assertStringContainsString(
            "'sortOrder' => SORT_ASC, 'id' => SORT_ASC",
            $this->methodSource(MenuBuilderItemService::class, 'getFlatForGroup')
        );
    }

    // ---------------------------------------------------------------------
    // helpers — a move applied to an in-memory group
    // ---------------------------------------------------------------------

    /** @var array<string,int> */
    private array $ids = [];

    /**
     * Everything MenuBuilderItemService::move() does to the data: plan the
     * move from the current rows, write the new parentId, write the planned
     * sortOrders. Nothing else in the service touches hierarchy columns.
     *
     * @param array<int,array{id:int,parentId:int|null,sortOrder:int}> $rows
     * @param int[] $requestedSiblingIds
     * @return array<int,array{id:int,parentId:int|null,sortOrder:int}>
     */
    private function applyMove(array $rows, string $item, ?string $newParent, int $newSortOrder, array $requestedSiblingIds = []): array
    {
        $itemId = $this->id($item);
        $newParentId = $newParent === null ? null : $this->id($newParent);
        $plan = Hierarchy::planMove($rows, $itemId, $newParentId, $newSortOrder, $requestedSiblingIds);

        return $this->apply($rows, $itemId, $newParentId, $plan);
    }

    /**
     * @param array<int,array{id:int,parentId:int|null,sortOrder:int}> $rows
     * @param array{parentId:int|null,sortOrders:array<int,int>} $plan
     * @return array<int,array{id:int,parentId:int|null,sortOrder:int}>
     */
    private function apply(array $rows, int $itemId, ?int $newParentId, array $plan): array
    {
        return array_map(function(array $row) use ($itemId, $newParentId, $plan) {
            if ($row['id'] === $itemId) {
                $row['parentId'] = $newParentId;
            }

            if (isset($plan['sortOrders'][$row['id']])) {
                $row['sortOrder'] = $plan['sortOrders'][$row['id']];
            }

            return $row;
        }, $rows);
    }

    /**
     * Builds a group from a `title => parent title` map, numbering rows in
     * declaration order within each sibling set — the state a freshly built
     * menu is in.
     *
     * @param array<string,string|null> $spec
     * @return array<int,array{id:int,parentId:int|null,sortOrder:int}>
     */
    private function rows(array $spec): array
    {
        $this->ids = [];
        $next = 1;

        foreach (array_keys($spec) as $title) {
            $this->ids[$title] = $next++;
        }

        $rows = [];
        $counters = [];

        foreach ($spec as $title => $parent) {
            $key = $parent ?? '';
            $counters[$key] = ($counters[$key] ?? -1) + 1;
            $rows[] = [
                'id' => $this->ids[$title],
                'parentId' => $parent === null ? null : $this->ids[$parent],
                'sortOrder' => $counters[$key],
            ];
        }

        return $rows;
    }

    /** @return array<int,array{id:int,parentId:int|null,sortOrder:int}> */
    private function flatRows(int $size): array
    {
        $rows = [];

        for ($id = 1; $id <= $size; $id++) {
            $rows[] = ['id' => $id, 'parentId' => null, 'sortOrder' => $id - 1];
        }

        return $rows;
    }

    private function id(string $title): int
    {
        return $this->ids[$title];
    }

    /**
     * Renders the group the way the CP does — depth-first, two spaces per
     * level — so an expected tree reads as the tree.
     *
     * @param array<int,array{id:int,parentId:int|null,sortOrder:int}> $rows
     * @return string[]
     */
    private function outline(array $rows): array
    {
        $titles = array_flip($this->ids);
        $childMap = Hierarchy::childMap($rows);
        $lines = [];

        $walk = function(?int $parentId, int $depth) use (&$walk, $childMap, $titles, &$lines) {
            foreach (Hierarchy::siblingIds($childMap, $parentId) as $id) {
                $lines[] = str_repeat('  ', $depth) . $titles[$id];
                $walk($id, $depth + 1);
            }
        };

        $walk(null, 0);

        return $lines;
    }

    /** @param array<int,array{id:int,parentId:int|null,sortOrder:int}> $rows */
    private function sortOrderOf(array $rows, string $title): int
    {
        foreach ($rows as $row) {
            if ($row['id'] === $this->id($title)) {
                return $row['sortOrder'];
            }
        }

        $this->fail("No row for $title.");
    }

    /**
     * @param array<int,array{id:int,parentId:int|null,sortOrder:int}> $rows
     * @return int[]
     */
    private function sortOrdersOfSiblings(array $rows, ?int $parentId): array
    {
        $byId = array_column($rows, 'sortOrder', 'id');
        $orders = array_map(
            fn(int $id) => $byId[$id],
            Hierarchy::siblingIds(Hierarchy::childMap($rows), $parentId)
        );
        sort($orders);

        return $orders;
    }

    /** @param class-string $class */
    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}

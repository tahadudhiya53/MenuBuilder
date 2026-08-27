<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\services\MenuBuilderItemService;

/**
 * Navigation item CRUD and hierarchy.
 *
 * Two kinds of test live here. The tree-assembly cases are real behaviour —
 * getTree() is pure once its single flat query is stubbed, so nesting,
 * sibling ordering and orphan handling are exercised for real. The rest are
 * structural invariants of the lifecycle methods (validation runs before the
 * write, multi-row mutations are transactional, every field survives the
 * round trip, every mutation goes through the service): the properties that
 * regress silently, because the happy path keeps working either way and only
 * a partial failure, a lost field, or a bypassed check exposes them.
 *
 * The DB-backed behaviour these guard — the parentId cascade actually
 * firing, a real duplicated subtree, a real circular-move rejection — needs
 * a booted Craft app and is verified manually (see "Known limitations" #2 in
 * ARCHITECTURE.md).
 *
 * @see MenuBuilderItemModelTest for the field-level validation rules.
 * @see ControllerPermissionTest for the per-action permission mapping.
 */
class MenuBuilderItemLifecycleTest extends TestCase
{
    // ---------------------------------------------------------------------
    // READ — tree assembly (real behaviour, one query stubbed)
    // ---------------------------------------------------------------------

    public function testTreeNestsChildrenUnderTheirParent(): void
    {
        $tree = $this->treeFrom([
            $this->item(1, null, 0, 'Root'),
            $this->item(2, 1, 0, 'Child'),
            $this->item(3, 2, 0, 'Grandchild'),
        ]);

        $this->assertCount(1, $tree);
        $this->assertSame('Root', $tree[0]->title);
        $this->assertSame('Child', $tree[0]->children[0]->title);
        $this->assertSame('Grandchild', $tree[0]->children[0]->children[0]->title);
    }

    public function testTreeOrdersSiblingsBySortOrderAtEveryLevel(): void
    {
        $tree = $this->treeFrom([
            $this->item(1, null, 1, 'Second'),
            $this->item(2, null, 0, 'First'),
            $this->item(3, 2, 2, 'C'),
            $this->item(4, 2, 0, 'A'),
            $this->item(5, 2, 1, 'B'),
        ]);

        $this->assertSame(['First', 'Second'], array_map(fn($i) => $i->title, $tree));
        $this->assertSame(['A', 'B', 'C'], array_map(fn($i) => $i->title, $tree[0]->children));
    }

    /**
     * A parentId pointing outside the group's own rows (a stale reference,
     * or a parent that was deleted) must not make the item disappear from
     * the tree — it surfaces as a root so an editor can still see and fix
     * it.
     */
    public function testItemWithAnUnresolvableParentSurfacesAsARoot(): void
    {
        $tree = $this->treeFrom([
            $this->item(1, null, 0, 'Root'),
            $this->item(2, 999, 0, 'Orphan'),
        ]);

        $this->assertSame(['Root', 'Orphan'], array_map(fn($i) => $i->title, $tree));
    }

    public function testTreeIsEmptyForAGroupWithNoItems(): void
    {
        $this->assertSame([], $this->treeFrom([]));
    }

    // ---------------------------------------------------------------------
    // CREATE / READ / UPDATE / DELETE / DUPLICATE — the surface itself
    // ---------------------------------------------------------------------

    /**
     * Create and update share one `save` action (the `itemId` body param
     * decides which, and with it the required permission); read, delete and
     * duplicate each have their own. A missing action here means a lifecycle
     * operation has quietly gone.
     *
     * @dataProvider crudActionProvider
     */
    public function testEveryLifecycleOperationHasAnActionThatDelegatesToTheService(string $action): void
    {
        $method = 'action' . ucfirst($action);

        $this->assertTrue(
            method_exists(ItemsController::class, $method),
            "ItemsController::$method() is missing."
        );
        $source = $this->methodSource(ItemsController::class, $method);

        // Either straight off the plugin (`->items->…`) or via the local
        // `$itemsService` handle the multi-call actions take first.
        $this->assertTrue(
            str_contains($source, 'items->') || str_contains($source, '$itemsService->'),
            "ItemsController::$method() must go through the item service."
        );
    }

    /** @return array<string,array{string}> */
    public static function crudActionProvider(): array
    {
        $actions = ['edit', 'save', 'delete', 'duplicate', 'toggle', 'bulk'];

        return array_combine($actions, array_map(fn(string $action) => [$action], $actions));
    }

    /**
     * Craft only enforces CSRF on POST, so a mutation reachable over GET is
     * a mutation without CSRF protection.
     *
     * @dataProvider mutatingActionProvider
     */
    public function testEveryMutatingActionRequiresPost(string $action): void
    {
        $this->assertStringContainsString(
            'requirePostRequest()',
            $this->methodSource(ItemsController::class, 'action' . ucfirst($action)),
            "ItemsController::action" . ucfirst($action) . '() must reject non-POST requests.'
        );
    }

    /** @return array<string,array{string}> */
    public static function mutatingActionProvider(): array
    {
        $actions = ['save', 'delete', 'duplicate', 'toggle', 'reorder', 'bulk'];

        return array_combine($actions, array_map(fn(string $action) => [$action], $actions));
    }

    /**
     * Controllers must not write item rows themselves — validation,
     * hierarchy checks, transactions and cache invalidation all live in the
     * service, and any of them is skippable by a controller that reaches for
     * the record directly.
     */
    public function testControllerNeverTouchesTheRecordLayer(): void
    {
        $source = file_get_contents((new ReflectionClass(ItemsController::class))->getFileName());

        $this->assertStringNotContainsString('MenuBuilderItemRecord', $source);
    }

    /**
     * An existing item's group is fixed at creation, so the posted (hidden,
     * therefore tamperable) `groupId` may only be honoured for a brand new
     * item — otherwise moving a parent alone would leave its children behind
     * in the old group as orphaned roots.
     */
    public function testSaveActionOnlyHonoursThePostedGroupIdForANewItem(): void
    {
        $this->assertStringContainsString(
            '$item->groupId = $item->id !== null ? $item->groupId : $postedGroupId;',
            $this->methodSource(ItemsController::class, 'actionSave')
        );
    }

    /**
     * `metadata` is assembled from discrete, explicitly named POST fields.
     * Accepting a posted `metadata[...]` array directly would let a tampered
     * request write arbitrary keys into a bag that is read back out in Twig.
     */
    public function testSaveActionNeverAcceptsARawMetadataOrVisibilityPayload(): void
    {
        $source = $this->methodSource(ItemsController::class, 'actionSave');

        $this->assertStringContainsString('$this->buildMetadata($request, $item->type)', $source);
        $this->assertStringContainsString('$this->buildVisibilityRules(', $source);
        $this->assertStringNotContainsString("getBodyParam('metadata'", $source);
    }

    // ---------------------------------------------------------------------
    // Field coverage — every configurable property survives the round trip
    // ---------------------------------------------------------------------

    /**
     * The regression this exists for: adding a field to MenuBuilderItem (or
     * to the CP form) and forgetting it in `save()` or `recordToModel()`
     * loses editor input silently — the save still succeeds, the value just
     * never comes back.
     *
     * @dataProvider persistedPropertyProvider
     */
    public function testEveryPersistedPropertyIsWrittenAndReadBack(string $property): void
    {
        $this->assertStringContainsString(
            '$record->' . $property . ' =',
            $this->saveSource(),
            "save() never writes \$item->$property."
        );
        $this->assertStringContainsString(
            '$item->' . $property . ' =',
            $this->methodSource(MenuBuilderItemService::class, 'recordToModel'),
            "recordToModel() never reads back $property."
        );
    }

    /**
     * Duplication is a separate hand-written copy of the same column list,
     * so it drifts the same way — a field added to `save()` but not to
     * `duplicateRecord()` is silently dropped from every clone.
     *
     * `handle` and `htmlId` are the deliberate exceptions: both are
     * identifiers that must stay unique, so the clone starts without them.
     *
     * @dataProvider persistedPropertyProvider
     */
    public function testEveryPersistedPropertyIsCarriedIntoADuplicate(string $property): void
    {
        $source = $this->methodSource(MenuBuilderItemService::class, 'duplicateRecord');

        if (in_array($property, ['handle', 'htmlId'], true)) {
            $this->assertStringContainsString(
                '$clone->' . $property . ' = null;',
                $source,
                "A duplicate must not inherit the original's $property."
            );

            return;
        }

        $this->assertStringContainsString(
            '$clone->' . $property . ' =',
            $source,
            "duplicateRecord() never copies $property."
        );
    }

    /**
     * Derived from the model itself rather than hard-coded, so a newly added
     * field joins both tests above automatically instead of quietly sitting
     * outside them.
     *
     * `sortOrder` is excluded because it is assigned by position, not
     * copied; `id`/`uid`/`dateCreated`/`dateUpdated` are database-owned; and
     * `children` is assembled by getTree() rather than stored.
     *
     * @return array<string,array{string}>
     */
    public static function persistedPropertyProvider(): array
    {
        $excluded = ['id', 'uid', 'dateCreated', 'dateUpdated', 'children', 'sortOrder'];

        $properties = array_values(array_diff(
            array_map(
                fn(ReflectionProperty $property) => $property->getName(),
                (new ReflectionClass(MenuBuilderItem::class))->getProperties(ReflectionProperty::IS_PUBLIC)
            ),
            $excluded
        ));

        return array_combine($properties, array_map(fn(string $property) => [$property], $properties));
    }

    /**
     * Guards the provider above: if the model's public surface ever stops
     * being reflectable the way this assumes, the two coverage tests would
     * silently pass with an empty property list.
     */
    public function testPersistedPropertyProviderCoversTheKnownFieldGroups(): void
    {
        $properties = array_keys(self::persistedPropertyProvider());

        foreach ([
            'groupId', 'parentId', 'title', 'type', 'enabled', 'clickable', 'elementId', 'customUrl',
            'target', 'rel', 'cssClass', 'htmlId', 'htmlAttributes', 'ariaLabel', 'titleAttribute',
            'icon', 'badge', 'description', 'image', 'featured', 'fallbackBehavior', 'fallbackUrl',
            'visibility', 'metadata', 'handle',
        ] as $expected) {
            $this->assertContains($expected, $properties);
        }
    }

    // ---------------------------------------------------------------------
    // Validation and hierarchy integrity
    // ---------------------------------------------------------------------

    /** Validation is the model's job, and save() must not be able to skip it by default. */
    public function testSaveRunsModelValidationByDefault(): void
    {
        $runValidation = (new ReflectionMethod(MenuBuilderItemService::class, 'save'))->getParameters()[1];

        $this->assertSame('runValidation', $runValidation->getName());
        $this->assertTrue($runValidation->getDefaultValue());
        $this->assertStringContainsString('$item->validate()', $this->methodSource(MenuBuilderItemService::class, 'save'));
    }

    /**
     * Every hierarchy rule is enforced in one place, server-side, and that
     * place is reached before anything is written — the CP's own checks are
     * UX only.
     */
    public function testHierarchyIsValidatedBeforeTheRowIsWritten(): void
    {
        $source = $this->saveSource();

        $this->assertStringContainsString('validateHierarchy($item)', $source);
        $this->assertLessThan(
            strpos($source, '$record->save()'),
            strpos($source, 'validateHierarchy($item)'),
            'Hierarchy validation must short-circuit before the row is written.'
        );
    }

    public function testMoveRevalidatesHierarchyServerSide(): void
    {
        $this->assertStringContainsString(
            'validateHierarchy($item)',
            $this->methodSource(MenuBuilderItemService::class, 'move')
        );
    }

    /**
     * The four hierarchy rules, each as its own field error rather than a
     * database integrity exception: no self-parent, the parent must exist,
     * the parent must be in the same group, and no cycle (which covers
     * reparenting an item under its own descendant, since that descendant's
     * ancestor walk passes through the item).
     */
    public function testValidateHierarchyCoversEveryStructuralRule(): void
    {
        $source = $this->methodSource(MenuBuilderItemService::class, 'validateHierarchy');

        $this->assertStringContainsString('$item->parentId === $item->id', $source);
        $this->assertStringContainsString('The selected parent does not exist.', $source);
        $this->assertStringContainsString('A parent must belong to the same navigation group.', $source);
        $this->assertStringContainsString('That move would create a circular reference.', $source);
        $this->assertStringContainsString('allowsDepth(', $source);
        $this->assertStringContainsString("addError('parentId'", $source);
    }

    /**
     * The self-parent and cycle checks only catch a cycle running *through
     * the item being saved*. A cycle that already exists in the stored rows
     * — two concurrent moves, or a hand-edited row — would otherwise be
     * walked forever, so every walk carries a visited set and the
     * validation fails closed on one.
     *
     * @see MenuBuilderTreeMoveTest for the behavioural coverage of the walks
     * themselves, which live in MenuBuilderHierarchyHelper.
     */
    public function testAncestorWalksAreGuardedAgainstAPreExistingCycle(): void
    {
        $source = $this->methodSource(MenuBuilderItemService::class, 'validateHierarchy');

        $this->assertStringContainsString('ancestryIsCyclic(', $source);
        $this->assertLessThan(
            strpos($source, 'deepestLevelAfterMove('),
            strpos($source, 'ancestryIsCyclic('),
            'Depth measured above a cyclic ancestry is meaningless, so the cycle must be caught first.'
        );
    }

    /**
     * A new item naming a group that isn't there — deleted in another tab,
     * or a tampered/imported payload — must fail as a field error on
     * `groupId`, not as the FK's integrity exception surfacing to the
     * editor as a 500.
     */
    public function testSaveRejectsAMissingOwningGroupBeforeWriting(): void
    {
        $source = $this->saveSource();

        $this->assertStringContainsString('$isNew && !$this->groupExists($item->groupId)', $source);
        $this->assertStringContainsString("addError('groupId'", $source);
        $this->assertLessThan(
            strpos($source, '$record->save()'),
            strpos($source, '$this->groupExists($item->groupId)'),
            'The group existence check must short-circuit before the row is written.'
        );
    }

    /** An item belongs to exactly one group, for the lifetime of the item. */
    public function testAnItemsGroupIsFixedAtCreation(): void
    {
        $this->assertTrue(MenuBuilderItemService::isGroupChangeAllowed(null, 7), 'A new item may pick its group.');
        $this->assertTrue(MenuBuilderItemService::isGroupChangeAllowed(7, 7));
        $this->assertFalse(MenuBuilderItemService::isGroupChangeAllowed(7, 8));
    }

    /**
     * Reparenting through the edit form lands the item in a sibling set it
     * never had a position in, so carrying its old sortOrder over would
     * collide with whichever sibling already holds that number.
     */
    public function testReparentingReassignsSortOrderInTheNewSiblingSet(): void
    {
        $source = $this->saveSource();

        $this->assertStringContainsString('$isReparent', $source);
        $this->assertStringContainsString('($isNew || $isReparent)', $source);
        $this->assertStringContainsString('nextSortOrder($item->groupId, $item->parentId)', $source);
    }

    // ---------------------------------------------------------------------
    // DELETE and DUPLICATE semantics
    // ---------------------------------------------------------------------

    /**
     * Deleting a parent has exactly two defined outcomes, and the caller
     * chooses: drop the whole subtree (the cascading FK on parentId), or
     * keep the children by lifting them to the deleted item's own parent.
     */
    public function testDeleteOffersBothDescendantStrategies(): void
    {
        $keepChildren = (new ReflectionMethod(MenuBuilderItemService::class, 'deleteById'))->getParameters()[1];

        $this->assertSame('keepChildren', $keepChildren->getName());
        $this->assertFalse($keepChildren->getDefaultValue(), 'The default is the cascade, matching the FK.');

        $source = $this->methodSource(MenuBuilderItemService::class, 'deleteById');

        $this->assertStringContainsString('$child->parentId = $newParentId;', $source);
        $this->assertStringContainsString('nextSortOrder($groupId, $newParentId)', $source);
    }

    /**
     * Reparenting the children and deleting the parent must land or fail
     * together — a committed delete with half its children lifted would
     * strand the rest.
     */
    public function testKeepChildrenDeleteRunsInATransaction(): void
    {
        $source = $this->methodSource(MenuBuilderItemService::class, 'deleteById');

        $this->assertStringContainsString('beginTransaction', $source);
        $this->assertStringContainsString('rollBack', $source);
    }

    /**
     * The endpoint never guesses what should happen to a subtree: it asks
     * first, and only acts once the editor has answered.
     */
    public function testDeleteActionAsksBeforeDestroyingASubtree(): void
    {
        $source = $this->methodSource(ItemsController::class, 'actionDelete');

        $this->assertStringContainsString('requiresChoice', $source);
        $this->assertStringContainsString('hasChildren($id)', $source);
        $this->assertStringContainsString('$keepChildrenParam === null', $source);
    }

    /** Duplicating a subtree is many inserts; a half-copied tree must never commit. */
    public function testDuplicateRunsInATransaction(): void
    {
        $source = $this->methodSource(MenuBuilderItemService::class, 'duplicate');

        $this->assertStringContainsString('beginTransaction', $source);
        $this->assertStringContainsString('rollBack', $source);
    }

    /**
     * The clone is built from a fresh record and recurses over the
     * original's children, so every copied node gets its own ID and the
     * originals are never touched.
     */
    public function testDuplicateCopiesTheWholeSubtreeIntoFreshRecords(): void
    {
        $source = $this->methodSource(MenuBuilderItemService::class, 'duplicateRecord');

        $this->assertStringContainsString('$clone = new MenuBuilderItemRecord();', $source);
        $this->assertStringContainsString('$this->duplicateRecord($child, $clone->id', $source);
        $this->assertStringNotContainsString('$original->save', $source);
        $this->assertStringNotContainsString('$original->delete', $source);
    }

    /**
     * `nextSortOrder()` numbers each child as it is written, so an unordered
     * child query would hand the copy whatever order the database felt like
     * returning instead of the original's.
     */
    public function testDuplicatePreservesSiblingOrder(): void
    {
        $this->assertStringContainsString(
            "orderBy(['sortOrder' => SORT_ASC",
            $this->methodSource(MenuBuilderItemService::class, 'duplicateRecord')
        );
    }

    /**
     * A failed insert leaves `$clone->id` null, and every descendant below
     * would then be written with `parentId = null` — committing the copy as
     * a pile of orphaned roots. Throwing hands the surrounding transaction
     * its rollback instead.
     */
    public function testDuplicateAbortsRatherThanCommittingOrphanedDescendants(): void
    {
        $source = $this->methodSource(MenuBuilderItemService::class, 'duplicateRecord');

        $this->assertStringContainsString('if (!$clone->save(false)) {', $source);
        $this->assertStringContainsString('throw new Exception', $source);
    }

    // ---------------------------------------------------------------------
    // Bulk operations and cache invalidation
    // ---------------------------------------------------------------------

    /**
     * @dataProvider transactionalMethodProvider
     */
    public function testMultiRowMutationsRunInATransaction(string $method): void
    {
        $source = $this->methodSource(MenuBuilderItemService::class, $method);

        $this->assertStringContainsString('beginTransaction', $source);
        $this->assertStringContainsString('rollBack', $source);
    }

    /** @return array<string,array{string}> */
    public static function transactionalMethodProvider(): array
    {
        $methods = ['move', 'reorderSiblings', 'duplicate', 'bulkSetEnabled', 'bulkDelete'];

        return array_combine($methods, array_map(fn(string $method) => [$method], $methods));
    }

    /**
     * A bulk toggle must not become a second, looser write path: it reuses
     * the same save() every single toggle goes through, so the hierarchy and
     * group checks can't be bypassed by batching.
     */
    public function testBulkEnableReusesTheSingleItemSavePath(): void
    {
        $this->assertStringContainsString(
            '$this->save($item',
            $this->methodSource(MenuBuilderItemService::class, 'bulkSetEnabled')
        );
    }

    /**
     * Every write must invalidate the owning group's cached render,
     * otherwise the CP shows the change and the front end doesn't.
     *
     * @dataProvider cacheInvalidatingMethodProvider
     */
    public function testEveryWritePathInvalidatesTheGroupCache(string $method): void
    {
        $this->assertStringContainsString(
            'invalidateGroup',
            $this->methodSource(MenuBuilderItemService::class, $method),
            "$method() changes what the front end renders and must invalidate the group's cache."
        );
    }

    /** @return array<string,array{string}> */
    public static function cacheInvalidatingMethodProvider(): array
    {
        $methods = ['save', 'move', 'reorderSiblings', 'duplicate', 'deleteById'];

        return array_combine($methods, array_map(fn(string $method) => [$method], $methods));
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    /**
     * getTree() is pure apart from its single flat query, so stubbing that
     * one method exercises the real assembly and ordering logic.
     *
     * @param MenuBuilderItem[] $flat
     * @return MenuBuilderItem[]
     */
    private function treeFrom(array $flat): array
    {
        $service = $this->createPartialMock(MenuBuilderItemService::class, ['getFlatForGroup']);
        $service->method('getFlatForGroup')->willReturn($flat);

        return $service->getTree(1);
    }

    private function item(int $id, ?int $parentId, int $sortOrder, string $title): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->id = $id;
        $item->groupId = 1;
        $item->parentId = $parentId;
        $item->sortOrder = $sortOrder;
        $item->title = $title;

        return $item;
    }

    /**
     * `save()` splits its structural half into `saveRecord()` so a reparent
     * can run inside one locked transaction while a field-only edit doesn't
     * pay for one. The two together are still the single save path, which is
     * what these tests are about.
     */
    private function saveSource(): string
    {
        return $this->methodSource(MenuBuilderItemService::class, 'save')
            . $this->methodSource(MenuBuilderItemService::class, 'saveRecord');
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

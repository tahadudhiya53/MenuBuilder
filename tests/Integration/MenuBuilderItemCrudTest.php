<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\services\MenuBuilderItemService;

/**
 * Item CRUD, hierarchy and ordering against the real database.
 *
 * The unit suite exercises the *plan* a move produces (see
 * MenuBuilderTreeTest, which drives `MenuBuilderHierarchyHelper` over
 * in-memory snapshots) and the rules the item model enforces. What it cannot
 * do is run the plan: whether the rows it names are actually written, whether
 * a refused move rolls back cleanly, whether a delete really takes the
 * subtree, and whether the sibling set is still contiguous afterwards. That
 * is this class.
 *
 * Each test works in a menu of its own, deleted afterwards — the cascading
 * foreign key takes the items with it.
 */
class MenuBuilderItemCrudTest extends TestCase
{
    private MenuBuilderGroup $menu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->menu = new MenuBuilderGroup();
        $this->menu->name = 'Item CRUD';
        $this->menu->handle = 'itemCrud' . bin2hex(random_bytes(4));

        $this->assertTrue(MenuBuilder::getInstance()->groups->save($this->menu));
    }

    protected function tearDown(): void
    {
        MenuBuilder::getInstance()->groups->deleteById((int)$this->menu->id);

        parent::tearDown();
    }

    private function items(): MenuBuilderItemService
    {
        return MenuBuilder::getInstance()->items;
    }

    private function add(string $title, ?int $parentId = null, ?callable $configure = null): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = (int)$this->menu->id;
        $item->parentId = $parentId;
        $item->title = $title;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = '/' . strtolower(str_replace(' ', '-', $title));

        if ($configure) {
            $configure($item);
        }

        $this->assertTrue($this->items()->save($item), json_encode($item->getErrors()));

        return $item;
    }

    /** Titles of the given level, in stored order. */
    private function titlesUnder(?int $parentId): array
    {
        $titles = [];

        foreach ($this->items()->getFlatForGroup((int)$this->menu->id) as $item) {
            if ((int)$item->parentId === (int)$parentId || ($parentId === null && $item->parentId === null)) {
                $titles[(int)$item->sortOrder] = $item->title;
            }
        }

        ksort($titles);

        return array_values($titles);
    }

    /** @return int[] The sortOrders of one sibling set, ascending. */
    private function sortOrdersUnder(?int $parentId): array
    {
        $orders = [];

        foreach ($this->items()->getFlatForGroup((int)$this->menu->id) as $item) {
            if ((int)$item->parentId === (int)$parentId || ($parentId === null && $item->parentId === null)) {
                $orders[] = (int)$item->sortOrder;
            }
        }

        sort($orders);

        return $orders;
    }

    // ---------------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------------

    public function testASavedItemIsReadableBack(): void
    {
        $item = $this->add('Home');

        $reloaded = $this->items()->getById((int)$item->id);

        $this->assertNotNull($reloaded);
        $this->assertSame('Home', $reloaded->title);
        $this->assertSame('/home', $reloaded->customUrl);
        $this->assertSame((int)$this->menu->id, (int)$reloaded->groupId);
        $this->assertNotEmpty($reloaded->uid);
    }

    /**
     * Presentation, accessibility, link behaviour, visibility and the two
     * JSON bags all have to survive one round trip — a column that silently
     * drops its value only shows up here.
     */
    public function testEveryPersistedPropertyRoundTripsThroughTheDatabase(): void
    {
        $item = $this->add('Everything', configure: function(MenuBuilderItem $item) {
            $item->handle = 'everything';
            $item->enabled = false;
            $item->target = '_blank';
            $item->rel = 'nofollow';
            $item->cssClass = 'nav-item featured';
            $item->htmlId = 'nav-everything';
            $item->htmlAttributes = ['data-track' => 'nav'];
            $item->ariaLabel = 'Everything, opens in a new tab';
            $item->titleAttribute = 'Everything';
            $item->icon = 'fa-solid fa-star';
            $item->badge = 'New';
            $item->description = 'A description.';
            $item->featured = true;
            $item->fallbackBehavior = MenuBuilderItem::FALLBACK_HIDE;
            $item->visibility = [['type' => 'loggedIn']];
            $item->metadata = ['badgeStyle' => 'success'];
        });

        $reloaded = $this->items()->getById((int)$item->id);

        $this->assertSame('everything', $reloaded->handle);
        $this->assertFalse($reloaded->enabled);
        $this->assertSame('_blank', $reloaded->target);
        $this->assertSame('nofollow', $reloaded->rel);
        $this->assertSame('nav-item featured', $reloaded->cssClass);
        $this->assertSame('nav-everything', $reloaded->htmlId);
        $this->assertSame(['data-track' => 'nav'], $reloaded->htmlAttributes);
        $this->assertSame('Everything, opens in a new tab', $reloaded->ariaLabel);
        $this->assertSame('Everything', $reloaded->titleAttribute);
        $this->assertSame('fa-solid fa-star', $reloaded->icon);
        $this->assertSame('New', $reloaded->badge);
        $this->assertSame('A description.', $reloaded->description);
        $this->assertTrue($reloaded->featured);
        $this->assertSame([['type' => 'loggedIn']], $reloaded->visibility);
        $this->assertSame(['badgeStyle' => 'success'], $reloaded->metadata);
    }

    public function testNewSiblingsAreAppendedInTheOrderTheyWereCreated(): void
    {
        $this->add('First');
        $this->add('Second');
        $this->add('Third');

        $this->assertSame(['First', 'Second', 'Third'], $this->titlesUnder(null));
    }

    public function testAChildIsCreatedUnderItsParentAndOrderedInItsOwnSet(): void
    {
        $parent = $this->add('Products');
        $this->add('Shoes', (int)$parent->id);
        $this->add('Hats', (int)$parent->id);

        $this->assertSame(['Products'], $this->titlesUnder(null));
        $this->assertSame(['Shoes', 'Hats'], $this->titlesUnder((int)$parent->id));
    }

    // ---------------------------------------------------------------------
    // Edit
    // ---------------------------------------------------------------------

    public function testAnEditUpdatesTheRowRatherThanAddingOne(): void
    {
        $item = $this->add('Home');

        $item->title = 'Start';
        $item->customUrl = '/start';
        $this->assertTrue($this->items()->save($item));

        $this->assertSame(1, $this->items()->countForGroup((int)$this->menu->id));
        $this->assertSame('Start', $this->items()->getById((int)$item->id)->title);
        $this->assertSame('/start', $this->items()->getById((int)$item->id)->customUrl);
    }

    public function testChangingATypeClearsNothingItStillNeeds(): void
    {
        $item = $this->add('Heading');

        $item->type = MenuBuilderItem::TYPE_NONCLICKABLE;
        $this->assertTrue($this->items()->save($item));

        $reloaded = $this->items()->getById((int)$item->id);
        $this->assertSame(MenuBuilderItem::TYPE_NONCLICKABLE, $reloaded->type);
        $this->assertSame('Heading', $reloaded->title);
    }

    public function testAnInvalidItemIsNeverWritten(): void
    {
        $invalid = new MenuBuilderItem();
        $invalid->groupId = (int)$this->menu->id;
        $invalid->type = MenuBuilderItem::TYPE_URL;
        $invalid->title = '';
        $invalid->customUrl = '';

        $this->assertFalse($this->items()->save($invalid));
        $this->assertSame(0, $this->items()->countForGroup((int)$this->menu->id));
    }

    /**
     * An item belongs to a menu, and the check has to short-circuit *before*
     * the insert — the foreign key would refuse the row anyway, but as an
     * exception rather than as a field error the editor can read.
     */
    public function testAnItemNamingAMenuThatDoesNotExistIsRefusedWithAFieldError(): void
    {
        $orphan = new MenuBuilderItem();
        $orphan->groupId = 999999;
        $orphan->type = MenuBuilderItem::TYPE_URL;
        $orphan->title = 'Orphan';
        $orphan->customUrl = '/orphan';

        $this->assertFalse($this->items()->save($orphan));
        $this->assertArrayHasKey('groupId', $orphan->getErrors());
        $this->assertNull($orphan->id);
    }

    public function testAnItemWithAnExecutableUrlIsRefusedBeforeItReachesTheDatabase(): void
    {
        $hostile = new MenuBuilderItem();
        $hostile->groupId = (int)$this->menu->id;
        $hostile->type = MenuBuilderItem::TYPE_URL;
        $hostile->title = 'Hostile';
        $hostile->customUrl = 'javascript:alert(1)';

        $this->assertFalse($this->items()->save($hostile));
        $this->assertSame(0, $this->items()->countForGroup((int)$this->menu->id));
    }

    public function testBulkEnableAndDisableWriteEveryNamedRow(): void
    {
        $a = $this->add('A');
        $b = $this->add('B');

        $this->assertTrue($this->items()->bulkSetEnabled([(int)$a->id, (int)$b->id], false));
        $this->assertFalse($this->items()->getById((int)$a->id)->enabled);
        $this->assertFalse($this->items()->getById((int)$b->id)->enabled);

        $this->assertTrue($this->items()->bulkSetEnabled([(int)$a->id, (int)$b->id], true));
        $this->assertTrue($this->items()->getById((int)$a->id)->enabled);
    }

    // ---------------------------------------------------------------------
    // Hierarchy
    // ---------------------------------------------------------------------

    public function testTheTreeNestsChildrenUnderTheirParent(): void
    {
        $products = $this->add('Products');
        $shoes = $this->add('Shoes', (int)$products->id);
        $this->add('Running', (int)$shoes->id);
        $this->add('About');

        $tree = $this->items()->getTree((int)$this->menu->id);

        $this->assertSame(['Products', 'About'], array_map(fn($i) => $i->title, $tree));
        $this->assertSame(['Shoes'], array_map(fn($i) => $i->title, $tree[0]->children));
        $this->assertSame(['Running'], array_map(fn($i) => $i->title, $tree[0]->children[0]->children));
    }

    public function testAnItemCannotBeItsOwnParent(): void
    {
        $item = $this->add('Home');

        $item->parentId = (int)$item->id;

        $this->assertFalse($this->items()->save($item));
        $this->assertNull($this->items()->getById((int)$item->id)->parentId);
    }

    public function testAnItemCannotBeMovedUnderItsOwnDescendant(): void
    {
        $parent = $this->add('Products');
        $child = $this->add('Shoes', (int)$parent->id);

        $this->assertFalse($this->items()->move((int)$parent->id, (int)$child->id, 0));
        $this->assertNotNull($this->items()->getLastMoveError());
        $this->assertNull($this->items()->getById((int)$parent->id)->parentId);
    }

    public function testAnItemCannotBeParentedIntoAnotherMenu(): void
    {
        $other = new MenuBuilderGroup();
        $other->name = 'Other';
        $other->handle = 'other' . bin2hex(random_bytes(4));
        $this->assertTrue(MenuBuilder::getInstance()->groups->save($other));

        $foreignParent = new MenuBuilderItem();
        $foreignParent->groupId = (int)$other->id;
        $foreignParent->title = 'Foreign';
        $foreignParent->type = MenuBuilderItem::TYPE_URL;
        $foreignParent->customUrl = '/foreign';
        $this->assertTrue($this->items()->save($foreignParent));

        $mine = $this->add('Mine');

        $this->assertFalse($this->items()->move((int)$mine->id, (int)$foreignParent->id, 0));
        $this->assertNull($this->items()->getById((int)$mine->id)->parentId);

        MenuBuilder::getInstance()->groups->deleteById((int)$other->id);
    }

    /**
     * `maxDepth` is measured against the deepest row of the subtree being
     * moved, not against the item itself — otherwise a one-level move could
     * push a grandchild past the limit.
     */
    public function testMaxDepthIsEnforcedAgainstTheWholeMovingSubtree(): void
    {
        $top = $this->add('Top');
        $branch = $this->add('Branch');
        $this->add('Leaf', (int)$branch->id);

        // Capped only now: the two-level tree above is already at the limit,
        // so it could not have been built under it.
        $this->menu->maxDepth = 2;
        $this->assertTrue(MenuBuilder::getInstance()->groups->save($this->menu));

        // Branch (with its leaf) under Top would put Leaf at level 3.
        $this->assertFalse($this->items()->move((int)$branch->id, (int)$top->id, 0));
        $this->assertNull($this->items()->getById((int)$branch->id)->parentId);
    }

    public function testAMoveWithinTheDepthLimitIsAllowed(): void
    {
        $this->menu->maxDepth = 2;
        $this->assertTrue(MenuBuilder::getInstance()->groups->save($this->menu));

        $top = $this->add('Top');
        $leaf = $this->add('Leaf');

        $this->assertTrue($this->items()->move((int)$leaf->id, (int)$top->id, 0));
        $this->assertSame((int)$top->id, (int)$this->items()->getById((int)$leaf->id)->parentId);
    }

    // ---------------------------------------------------------------------
    // Ordering / moving
    // ---------------------------------------------------------------------

    public function testMovingASiblingToTheTopRewritesTheWholeSet(): void
    {
        $this->add('A');
        $this->add('B');
        $c = $this->add('C');

        $this->assertTrue($this->items()->move((int)$c->id, null, 0));

        $this->assertSame(['C', 'A', 'B'], $this->titlesUnder(null));
        $this->assertSame([0, 1, 2], $this->sortOrdersUnder(null));
    }

    public function testMovingAnItemUnderAnotherClosesTheGapItLeaves(): void
    {
        $a = $this->add('A');
        $b = $this->add('B');
        $this->add('C');

        $this->assertTrue($this->items()->move((int)$b->id, (int)$a->id, 0));

        $this->assertSame(['A', 'C'], $this->titlesUnder(null));
        $this->assertSame([0, 1], $this->sortOrdersUnder(null));
        $this->assertSame(['B'], $this->titlesUnder((int)$a->id));
    }

    public function testMovingAnItemCarriesItsSubtreeWithIt(): void
    {
        $target = $this->add('Target');
        $branch = $this->add('Branch');
        $leaf = $this->add('Leaf', (int)$branch->id);

        $this->assertTrue($this->items()->move((int)$branch->id, (int)$target->id, 0));

        $this->assertSame((int)$target->id, (int)$this->items()->getById((int)$branch->id)->parentId);
        $this->assertSame((int)$branch->id, (int)$this->items()->getById((int)$leaf->id)->parentId);
    }

    public function testMovingAnItemToTheRootUnnestsIt(): void
    {
        $parent = $this->add('Parent');
        $child = $this->add('Child', (int)$parent->id);

        $this->assertTrue($this->items()->move((int)$child->id, null, 1));

        $this->assertNull($this->items()->getById((int)$child->id)->parentId);
        $this->assertSame(['Parent', 'Child'], $this->titlesUnder(null));
    }

    public function testReorderSiblingsPersistsTheGivenOrder(): void
    {
        $a = $this->add('A');
        $b = $this->add('B');
        $c = $this->add('C');

        $this->assertTrue($this->items()->reorderSiblings(
            (int)$this->menu->id,
            null,
            [(int)$c->id, (int)$b->id, (int)$a->id],
        ));

        $this->assertSame(['C', 'B', 'A'], $this->titlesUnder(null));
    }

    public function testARefusedMoveLeavesTheOrderExactlyAsItWas(): void
    {
        $parent = $this->add('Parent');
        $this->add('Child', (int)$parent->id);
        $this->add('Sibling');

        $before = $this->titlesUnder(null);

        $this->assertFalse($this->items()->move((int)$parent->id, (int)$parent->id, 0));

        $this->assertSame($before, $this->titlesUnder(null));
    }

    // ---------------------------------------------------------------------
    // Duplicate
    // ---------------------------------------------------------------------

    public function testDuplicatingAnItemCopiesItAlongsideTheOriginal(): void
    {
        $item = $this->add('Home', configure: fn(MenuBuilderItem $i) => $i->badge = 'New');

        $copy = $this->items()->duplicate((int)$item->id);

        $this->assertNotNull($copy);
        $this->assertNotSame((int)$item->id, (int)$copy->id);
        $this->assertSame('New', $this->items()->getById((int)$copy->id)->badge);
        $this->assertNull($this->items()->getById((int)$copy->id)->parentId);
        $this->assertSame(2, $this->items()->countForGroup((int)$this->menu->id));
    }

    /**
     * The copy is a whole new subtree, and every level of it is suffixed —
     * not just the item that was duplicated. That is deliberate: a copied
     * branch that read identically to the original would be impossible to
     * tell apart in the tree view.
     */
    public function testDuplicatingAParentCopiesItsWholeSubtree(): void
    {
        $parent = $this->add('Products');
        $child = $this->add('Shoes', (int)$parent->id);
        $this->add('Running', (int)$child->id);

        $copy = $this->items()->duplicate((int)$parent->id);

        $this->assertSame(6, $this->items()->countForGroup((int)$this->menu->id));

        $tree = $this->items()->getTree((int)$this->menu->id);
        $copied = array_values(array_filter($tree, fn($i) => (int)$i->id === (int)$copy->id))[0];

        $this->assertSame('Products 2', $copied->title);
        $this->assertSame(['Shoes 2'], array_map(fn($i) => $i->title, $copied->children));
        $this->assertSame(['Running 2'], array_map(fn($i) => $i->title, $copied->children[0]->children));

        // The original is untouched by the copy.
        $original = array_values(array_filter($tree, fn($i) => (int)$i->id === (int)$parent->id))[0];
        $this->assertSame(['Shoes'], array_map(fn($i) => $i->title, $original->children));
    }

    public function testDuplicatingAChildKeepsItUnderTheSameParent(): void
    {
        $parent = $this->add('Products');
        $child = $this->add('Shoes', (int)$parent->id);

        $copy = $this->items()->duplicate((int)$child->id);

        $this->assertSame((int)$parent->id, (int)$this->items()->getById((int)$copy->id)->parentId);
    }

    public function testDuplicatingAnItemThatDoesNotExistIsNull(): void
    {
        $this->assertNull($this->items()->duplicate(999999));
    }

    // ---------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------

    public function testDeletingAnItemTakesItsDescendantsByDefault(): void
    {
        $parent = $this->add('Products');
        $child = $this->add('Shoes', (int)$parent->id);
        $grandchild = $this->add('Running', (int)$child->id);
        $this->add('About');

        $this->assertTrue($this->items()->deleteById((int)$parent->id));

        $this->assertNull($this->items()->getById((int)$child->id));
        $this->assertNull($this->items()->getById((int)$grandchild->id));
        $this->assertSame(1, $this->items()->countForGroup((int)$this->menu->id));
    }

    public function testDeletingAnItemCanPromoteItsChildrenInstead(): void
    {
        $parent = $this->add('Products');
        $child = $this->add('Shoes', (int)$parent->id);
        $grandchild = $this->add('Running', (int)$child->id);

        $this->assertTrue($this->items()->deleteById((int)$parent->id, keepChildren: true));

        $this->assertNull($this->items()->getById((int)$parent->id));
        $this->assertNull($this->items()->getById((int)$child->id)->parentId);
        // Only the direct children are promoted — the rest of the subtree
        // stays hung off them.
        $this->assertSame((int)$child->id, (int)$this->items()->getById((int)$grandchild->id)->parentId);
    }

    public function testBulkDeleteRemovesEveryNamedItemAndItsDescendants(): void
    {
        $a = $this->add('A');
        $this->add('A child', (int)$a->id);
        $b = $this->add('B');
        $this->add('C');

        $this->assertTrue($this->items()->bulkDelete([(int)$a->id, (int)$b->id]));

        $this->assertSame(['C'], array_map(fn($i) => $i->title, $this->items()->getTree((int)$this->menu->id)));
    }

    public function testDeletingAnItemThatDoesNotExistIsFalse(): void
    {
        $this->assertFalse($this->items()->deleteById(999999));
    }

    public function testNoItemIsOrphanedByAnyOfThese(): void
    {
        $parent = $this->add('Products');
        $child = $this->add('Shoes', (int)$parent->id);
        $this->add('Running', (int)$child->id);

        $this->items()->duplicate((int)$parent->id);
        $this->items()->deleteById((int)$child->id, keepChildren: true);

        $this->assertSame([], $this->items()->getOrphanedItemIds((int)$this->menu->id));
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\elements\MenuBuilderItemContent;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService;

/**
 * Menu CRUD against the real database.
*/
class MenuBuilderGroupCrudTest extends TestCase
{
    /** @var int[] Menus created by the running test, torn down after it. */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            MenuBuilder::getInstance()->groups->deleteById($id);
        }

        $this->created = [];

        parent::tearDown();
    }

    private function menus(): MenuBuilderGroupService
    {
        return MenuBuilder::getInstance()->groups;
    }

    private function makeMenu(string $handle, ?callable $configure = null): MenuBuilderGroup
    {
        $group = new MenuBuilderGroup();
        $group->name = ucfirst($handle);
        $group->handle = $handle;

        if ($configure) {
            $configure($group);
        }

        $this->assertTrue($this->menus()->save($group), json_encode($group->getErrors()));
        $this->created[] = (int)$group->id;

        return $group;
    }

    private function addItem(int $groupId, string $title, ?int $parentId = null): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = $groupId;
        $item->parentId = $parentId;
        $item->title = $title;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = '/' . strtolower(str_replace(' ', '-', $title));

        $this->assertTrue(MenuBuilder::getInstance()->items->save($item), json_encode($item->getErrors()));

        return $item;
    }

    private function handle(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(4));
    }

    // Create

    public function testASavedMenuIsReadableByIdHandleAndUid(): void
    {
        $handle = $this->handle('crud');
        $menu = $this->makeMenu($handle);

        $this->assertNotNull($menu->id);
        $this->assertNotEmpty($menu->uid);

        $this->assertSame((int)$menu->id, (int)$this->menus()->getById((int)$menu->id)->id);
        $this->assertSame((int)$menu->id, (int)$this->menus()->getByHandle($handle)->id);
        $this->assertSame((int)$menu->id, (int)$this->menus()->getByUid((string)$menu->uid)->id);
    }

    /**
     * Every attribute the editor can set has to survive the trip through the columns and the
     * settings bag — including the site restriction, which lives *inside* `settings` rather than
     * in a column of its own, and the field layout, which is a row of Craft's that this menu only
     * points at.
    */
    public function testEveryEditableAttributeRoundTripsThroughTheDatabase(): void
    {
        $secondSite = Craft::$app->getSites()->getPrimarySite()->id;

        $menu = $this->makeMenu($this->handle('full'), function(MenuBuilderGroup $group) use ($secondSite) {
            $group->name = 'Full Menu';
            $group->description = 'Everything set.';
            $group->enabled = false;
            $group->maxDepth = 3;
            $group->cssClass = 'site-nav primary';
            $group->htmlAttributes = ['data-menu' => 'full'];
            $group->siteIds = [$secondSite];
            $group->setFieldLayout($this->layoutWithField('subtitle'));
        });

        $reloaded = $this->menus()->getById((int)$menu->id);

        $this->assertSame('Full Menu', $reloaded->name);
        $this->assertSame('Everything set.', $reloaded->description);
        $this->assertFalse($reloaded->enabled);
        $this->assertSame(3, $reloaded->maxDepth);
        $this->assertSame('site-nav primary', $reloaded->cssClass);
        $this->assertSame(['data-menu' => 'full'], $reloaded->htmlAttributes);
        $this->assertSame([$secondSite], $reloaded->siteIds);
        $this->assertNotNull($reloaded->fieldLayoutId);
        $this->assertTrue($reloaded->hasCustomFields());
        $this->assertSame(
            ['subtitle'],
            array_map(fn($field) => $field->handle, $reloaded->getFieldLayout()->getCustomFields()),
        );
    }

    /**
     * A duplicated menu gets its **own** field layout row.
    */
    public function testDuplicatingAMenuCopiesItsFieldLayoutRatherThanSharingIt(): void
    {
        $original = $this->makeMenu($this->handle('fl'), function(MenuBuilderGroup $group) {
            $group->setFieldLayout($this->layoutWithField('subtitle'));
        });

        $clone = $this->menus()->duplicate((int)$original->id);
        $this->created[] = (int)$clone->id;

        $this->assertNotNull($clone->fieldLayoutId);
        $this->assertNotSame($original->fieldLayoutId, $clone->fieldLayoutId);
        $this->assertSame(
            ['subtitle'],
            array_map(fn($field) => $field->handle, $clone->getFieldLayout()->getCustomFields()),
        );
    }

    /**
     * Deleting a menu takes its field layout with it.
    */
    public function testDeletingAMenuDeletesItsFieldLayout(): void
    {
        $menu = $this->makeMenu($this->handle('fld'), function(MenuBuilderGroup $group) {
            $group->setFieldLayout($this->layoutWithField('subtitle'));
        });

        $fieldLayoutId = (int)$menu->fieldLayoutId;
        $this->assertTrue($this->fieldLayoutExists($fieldLayoutId));

        $this->assertTrue($this->menus()->deleteById((int)$menu->id));
        $this->created = array_values(array_diff($this->created, [(int)$menu->id]));

        // Asked of the table, not of `Fields::getLayoutById()`: that memoizes, so a layout deleted
        // in this request still comes back from it.
        $this->assertFalse($this->fieldLayoutExists($fieldLayoutId));
    }

    /**
     * A one-field layout on the content element, built the way the CP's designer posts one: a tab
     * holding a CustomField layout element that points at a real, saved Craft field.
    */
    private function fieldLayoutExists(int $fieldLayoutId): bool
    {
        return (new \craft\db\Query())
            ->from(\craft\db\Table::FIELDLAYOUTS)
            ->where(['id' => $fieldLayoutId, 'dateDeleted' => null])
            ->exists();
    }

    private function layoutWithField(string $handle): FieldLayout
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);

        if ($field === null) {
            $field = new PlainText([
                'name' => ucfirst($handle),
                'handle' => $handle,
            ]);

            $this->assertTrue(Craft::$app->getFields()->saveField($field), 'Could not create the test field.');
        }

        $layout = new FieldLayout(['type' => MenuBuilderItemContent::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new CustomField($field)]);
        $layout->setTabs([$tab]);

        return $layout;
    }

    public function testANewMenuIsAppendedToTheEndOfTheOrder(): void
    {
        $first = $this->makeMenu($this->handle('ord'));
        $second = $this->makeMenu($this->handle('ord'));

        $this->assertGreaterThan(
            (int)$this->menus()->getById((int)$first->id)->sortOrder,
            (int)$this->menus()->getById((int)$second->id)->sortOrder,
        );
    }

    // Edit

    public function testAnEditUpdatesTheExistingRowRatherThanInsertingAnother(): void
    {
        $menu = $this->makeMenu($this->handle('edit'));
        $before = count($this->menus()->getAll());

        $menu->name = 'Renamed';
        $this->assertTrue($this->menus()->save($menu));

        $this->assertCount($before, $this->menus()->getAll());
        $this->assertSame('Renamed', $this->menus()->getById((int)$menu->id)->name);
    }

    /**
     * A menu's handle is part of how templates address it, so it has to be changeable — and the
     * old handle has to stop resolving the moment it is.
    */
    public function testRenamingTheHandleMovesTheMenuToTheNewHandle(): void
    {
        $old = $this->handle('was');
        $new = $this->handle('now');

        $menu = $this->makeMenu($old);
        $menu->handle = $new;
        $this->assertTrue($this->menus()->save($menu));

        $this->assertNull($this->menus()->getByHandle($old));
        $this->assertSame((int)$menu->id, (int)$this->menus()->getByHandle($new)->id);
    }

    public function testAMenuKeepsItsOrderWhenItIsEdited(): void
    {
        $menu = $this->makeMenu($this->handle('keep'));
        $order = (int)$this->menus()->getById((int)$menu->id)->sortOrder;

        $this->makeMenu($this->handle('other'));

        $menu->name = 'Edited';
        $this->assertTrue($this->menus()->save($menu));

        $this->assertSame($order, (int)$this->menus()->getById((int)$menu->id)->sortOrder);
    }

    public function testReorderPersistsTheGivenOrder(): void
    {
        $a = $this->makeMenu($this->handle('a'));
        $b = $this->makeMenu($this->handle('b'));
        $c = $this->makeMenu($this->handle('c'));

        $this->assertTrue($this->menus()->reorder([$c->id, $a->id, $b->id]));

        $order = array_map(
            fn(MenuBuilderGroup $g) => (int)$this->menus()->getById((int)$g->id)->sortOrder,
            [$c, $a, $b],
        );

        $this->assertSame([0, 1, 2], $order);
    }

    /**
     * The posted order is one editor's screen, not the truth: a menu somebody deleted since the
     * page loaded, an id from nowhere, and a repeat all have to be discarded rather than written.
     * Same rule as a drag's `siblingIds` on the item side.
    */
    public function testReorderIgnoresIdsThatArentMenus(): void
    {
        $a = $this->makeMenu($this->handle('ra'));
        $b = $this->makeMenu($this->handle('rb'));

        $gone = $this->makeMenu($this->handle('rgone'));
        $goneId = (int)$gone->id;
        $this->menus()->deleteById($goneId);
        $this->created = array_values(array_diff($this->created, [$goneId]));

        $this->assertTrue($this->menus()->reorder([$b->id, $goneId, 999999, $b->id, $a->id]));

        $this->assertSame(0, (int)$this->menus()->getById((int)$b->id)->sortOrder);
        $this->assertSame(1, (int)$this->menus()->getById((int)$a->id)->sortOrder);
        $this->assertNull($this->menus()->getById($goneId), 'A reorder must not resurrect a deleted menu.');
    }

    /**
     * A menu the client never mentioned keeps its place in the list rather than being dropped out
     * of the ordering — it simply follows the ones that were named.
    */
    public function testReorderKeepsMenusTheClientDidNotMention(): void
    {
        $a = $this->makeMenu($this->handle('rk1'));
        $b = $this->makeMenu($this->handle('rk2'));

        $this->assertTrue($this->menus()->reorder([$b->id]));

        $all = $this->menus()->getAll();
        $ids = array_map(static fn(MenuBuilderGroup $g): int => (int)$g->id, $all);

        $this->assertContains((int)$a->id, $ids, 'An unmentioned menu must survive a reorder.');
        $this->assertSame(0, (int)$this->menus()->getById((int)$b->id)->sortOrder);
    }

    /**
     * Whatever was posted, what lands is a contiguous 0..n-1 sequence over the menus that exist —
     * which is what keeps `getAll()`'s order stable and repeatable.
    */
    public function testReorderLeavesAGapFreeSequence(): void
    {
        $this->makeMenu($this->handle('rg1'));
        $this->makeMenu($this->handle('rg2'));

        $all = $this->menus()->getAll();
        $ids = array_map(static fn(MenuBuilderGroup $g): int => (int)$g->id, $all);

        $this->assertTrue($this->menus()->reorder(array_reverse($ids)));

        $orders = array_map(
            static fn(MenuBuilderGroup $g): int => (int)$g->sortOrder,
            $this->menus()->getAll(),
        );

        $this->assertSame(range(0, count($orders) - 1), $orders);
    }

    /**
     * The list order is not part of any cache key — a menu's cached tree is keyed by its own id,
     * handle and `dateUpdated` — so reordering must leave the menus themselves untouched.
    */
    public function testReorderChangesNothingButSortOrder(): void
    {
        $a = $this->makeMenu($this->handle('rt1'));
        $b = $this->makeMenu($this->handle('rt2'));

        $before = $this->menus()->getById((int)$a->id);
        $fingerprint = [$before->name, $before->handle, $before->enabled, $before->uid, $before->dateUpdated];

        $this->assertTrue($this->menus()->reorder([$b->id, $a->id]));

        $after = $this->menus()->getById((int)$a->id);

        $this->assertSame(
            $fingerprint,
            [$after->name, $after->handle, $after->enabled, $after->uid, $after->dateUpdated],
        );
    }

    // Validation

    public function testASecondMenuCannotTakeAHandleAlreadyInUse(): void
    {
        $handle = $this->handle('taken');
        $this->makeMenu($handle);

        $clash = new MenuBuilderGroup();
        $clash->name = 'Clash';
        $clash->handle = $handle;

        $this->assertFalse($this->menus()->save($clash));
        $this->assertArrayHasKey('handle', $clash->getErrors());
        $this->assertNull($clash->id);
    }

    /**
     * Its *own* handle is not "already in use" — otherwise no menu could ever be re-saved.
    */
    public function testAMenuMayKeepItsOwnHandleOnEdit(): void
    {
        $menu = $this->makeMenu($this->handle('same'));

        $menu->name = 'Edited';

        $this->assertTrue($this->menus()->save($menu));
    }

    public function testAnInvalidMenuIsNeverWritten(): void
    {
        $before = count($this->menus()->getAll());

        $invalid = new MenuBuilderGroup();
        $invalid->name = '';
        $invalid->handle = 'Not A Handle';

        $this->assertFalse($this->menus()->save($invalid));
        $this->assertCount($before, $this->menus()->getAll());
    }

    public function testSavingAMenuThatNoLongerExistsFailsRatherThanRecreatingIt(): void
    {
        $menu = $this->makeMenu($this->handle('gone'));
        $id = (int)$menu->id;

        $this->assertTrue($this->menus()->deleteById($id));
        $this->created = array_values(array_diff($this->created, [$id]));

        $this->assertFalse($this->menus()->save($menu));
        $this->assertNull($this->menus()->getById($id));
    }

    // Duplicate

    public function testDuplicatingCopiesTheMenuUnderAFreeHandle(): void
    {
        $handle = $this->handle('dup');
        $original = $this->makeMenu($handle, function(MenuBuilderGroup $group) {
            $group->maxDepth = 4;
            $group->cssClass = 'nav';
        });

        $copy = $this->menus()->duplicate((int)$original->id);
        $this->assertNotNull($copy);
        $this->created[] = (int)$copy->id;

        $this->assertNotSame((int)$original->id, (int)$copy->id);
        $this->assertNotSame($handle, $copy->handle);
        $this->assertSame($handle . '2', $copy->handle);
        $this->assertSame($original->name . ' Copy', $copy->name);
        $this->assertSame(4, $copy->maxDepth);
        $this->assertSame('nav', $copy->cssClass);
    }

    public function testDuplicatingCopiesTheWholeItemTreeIntoNewRows(): void
    {
        $original = $this->makeMenu($this->handle('tree'));
        $parent = $this->addItem((int)$original->id, 'Products');
        $this->addItem((int)$original->id, 'Shoes', (int)$parent->id);
        $this->addItem((int)$original->id, 'About');

        $copy = $this->menus()->duplicate((int)$original->id);
        $this->created[] = (int)$copy->id;

        $items = MenuBuilder::getInstance()->items;
        $tree = $items->getTree((int)$copy->id);

        $this->assertSame(['Products', 'About'], array_map(fn($i) => $i->title, $tree));
        $this->assertSame(['Shoes'], array_map(fn($i) => $i->title, $tree[0]->children));

        // New rows, not the originals re-parented.
        $this->assertNotSame((int)$parent->id, (int)$tree[0]->id);
        $this->assertSame(3, $items->countForGroup((int)$original->id));
        $this->assertSame(3, $items->countForGroup((int)$copy->id));
    }

    public function testDuplicatingAMenuWithNoItemsProducesAnEmptyMenu(): void
    {
        $original = $this->makeMenu($this->handle('empty'));

        $copy = $this->menus()->duplicate((int)$original->id);
        $this->created[] = (int)$copy->id;

        $this->assertSame(0, MenuBuilder::getInstance()->items->countForGroup((int)$copy->id));
    }

    public function testDuplicatingRepeatedlyKeepsFindingAFreeHandle(): void
    {
        $handle = $this->handle('again');
        $original = $this->makeMenu($handle);

        $first = $this->menus()->duplicate((int)$original->id);
        $this->created[] = (int)$first->id;
        $second = $this->menus()->duplicate((int)$original->id);
        $this->created[] = (int)$second->id;

        $this->assertSame($handle . '2', $first->handle);
        $this->assertSame($handle . '3', $second->handle);
    }

    /**
     * `name` is a `varchar(255)`, which MySQL counts in **characters**. A byte-based trim both
     * shortened a perfectly storable multibyte name and cut it inside a UTF-8 sequence, so
     * duplicating a menu named in accented or CJK characters failed outright.
    */
    public function testDuplicatingAMenuWithAMultibyteNameAtTheLimit(): void
    {
        $original = $this->makeMenu($this->handle('multibyte'), function(MenuBuilderGroup $group) {
            $group->name = str_repeat('é', 255);
        });

        $copy = $this->menus()->duplicate((int)$original->id);

        $this->assertNotNull($copy);
        $this->created[] = (int)$copy->id;
        $this->assertSame(255, mb_strlen($copy->name));
        $this->assertTrue(mb_check_encoding($copy->name, 'UTF-8'));
    }

    public function testDuplicatingAMenuWhoseNameAlreadyFillsTheColumn(): void
    {
        $original = $this->makeMenu($this->handle('longname'), function(MenuBuilderGroup $group) {
            $group->name = str_repeat('a', 255);
        });

        $copy = $this->menus()->duplicate((int)$original->id);

        $this->assertNotNull($copy);
        $this->created[] = (int)$copy->id;
        $this->assertSame(255, mb_strlen($copy->name));
    }

    public function testDuplicatingAMenuThatDoesNotExistIsNull(): void
    {
        $this->assertNull($this->menus()->duplicate(999999));
    }

    // Delete

    public function testDeletingAMenuTakesEveryItemInItWithIt(): void
    {
        $menu = $this->makeMenu($this->handle('del'));
        $parent = $this->addItem((int)$menu->id, 'Products');
        $child = $this->addItem((int)$menu->id, 'Shoes', (int)$parent->id);

        $id = (int)$menu->id;
        $this->assertTrue($this->menus()->deleteById($id));
        $this->created = array_values(array_diff($this->created, [$id]));

        $items = MenuBuilder::getInstance()->items;
        $this->assertNull($items->getById((int)$parent->id));
        $this->assertNull($items->getById((int)$child->id));
        $this->assertSame(0, $items->countForGroup($id));
    }

    public function testDeletingOneMenuLeavesTheOthersAndTheirItemsAlone(): void
    {
        $doomed = $this->makeMenu($this->handle('doomed'));
        $survivor = $this->makeMenu($this->handle('survivor'));
        $keep = $this->addItem((int)$survivor->id, 'Home');
        $this->addItem((int)$doomed->id, 'Gone');

        $id = (int)$doomed->id;
        $this->menus()->deleteById($id);
        $this->created = array_values(array_diff($this->created, [$id]));

        $this->assertNotNull(MenuBuilder::getInstance()->items->getById((int)$keep->id));
        $this->assertNotNull($this->menus()->getById((int)$survivor->id));
    }

    public function testDeletingAMenuThatDoesNotExistIsFalse(): void
    {
        $this->assertFalse($this->menus()->deleteById(999999));
    }

    /**
     * A deleted menu's handle is free again, and the new menu is a new menu.
    */
    public function testAHandleIsFreeAgainAfterItsMenuIsDeleted(): void
    {
        $handle = $this->handle('reuse');
        $first = $this->makeMenu($handle);
        $firstId = (int)$first->id;

        $this->menus()->deleteById($firstId);
        $this->created = array_values(array_diff($this->created, [$firstId]));

        $second = $this->makeMenu($handle);

        $this->assertNotSame($firstId, (int)$second->id);
        $this->assertSame((int)$second->id, (int)$this->menus()->getByHandle($handle)->id);
    }

    // Listing

    public function testTheListingCanExcludeDisabledMenus(): void
    {
        $enabled = $this->makeMenu($this->handle('on'));
        $disabled = $this->makeMenu($this->handle('off'), fn(MenuBuilderGroup $g) => $g->enabled = false);

        $enabledIds = array_map(fn(MenuBuilderGroup $g) => (int)$g->id, $this->menus()->getAll(includeDisabled: false));
        $allIds = array_map(fn(MenuBuilderGroup $g) => (int)$g->id, $this->menus()->getAll());

        $this->assertContains((int)$enabled->id, $enabledIds);
        $this->assertNotContains((int)$disabled->id, $enabledIds);
        $this->assertContains((int)$disabled->id, $allIds);
    }

    public function testCountItemsCountsDescendantsToo(): void
    {
        $menu = $this->makeMenu($this->handle('count'));
        $parent = $this->addItem((int)$menu->id, 'Products');
        $this->addItem((int)$menu->id, 'Shoes', (int)$parent->id);

        $this->assertSame(2, $this->menus()->countItems((int)$menu->id));
    }

    // Project config

    /**
     * Menus are database-backed by design (see ARCHITECTURE.md): they are content-shaped, edited by
     * people who do not deploy, and must not require a project-config sync to change.
    */
    public function testNoMenuLifecycleOperationWritesToProjectConfig(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $before = $projectConfig->get();

        $menu = $this->makeMenu($this->handle('pc'));
        $menu->name = 'Edited';
        $this->menus()->save($menu);
        $copy = $this->menus()->duplicate((int)$menu->id);
        $this->created[] = (int)$copy->id;
        $this->menus()->reorder([(int)$copy->id, (int)$menu->id]);
        $this->menus()->deleteById((int)$copy->id);
        $this->created = array_values(array_diff($this->created, [(int)$copy->id]));

        $this->assertSame($before, $projectConfig->get());
    }
}

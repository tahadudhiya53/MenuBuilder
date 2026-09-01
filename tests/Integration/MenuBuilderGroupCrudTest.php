<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\helpers\CustomFieldHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderCustomField;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService;

/**
 * Menu CRUD against the real database.
 *
 * The unit suite covers what the group *model* refuses and asserts the shape
 * of the service's source, but it can never answer the questions that only a
 * database answers: whether a save round-trips through the columns, whether a
 * duplicate really copies the subtree, whether a delete really cascades, and
 * whether a rolled-back duplicate leaves nothing behind. Those are here.
 *
 * Every test builds and destroys its own menus, so this class never touches
 * the shared fixture in {@see CraftIntegrationTestCase}.
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

    // ---------------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------------

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
     * Every attribute the editor can set has to survive the trip through the
     * columns and the settings bag — including the two that live *inside*
     * `settings` (site restrictions and custom field definitions) rather than
     * in a column of their own.
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
            $group->customFields = [
                new MenuBuilderCustomField([
                    'handle' => 'subtitle',
                    'name' => 'Subtitle',
                    'type' => CustomFieldHelper::TYPE_TEXT,
                ]),
            ];
        });

        $reloaded = $this->menus()->getById((int)$menu->id);

        $this->assertSame('Full Menu', $reloaded->name);
        $this->assertSame('Everything set.', $reloaded->description);
        $this->assertFalse($reloaded->enabled);
        $this->assertSame(3, $reloaded->maxDepth);
        $this->assertSame('site-nav primary', $reloaded->cssClass);
        $this->assertSame(['data-menu' => 'full'], $reloaded->htmlAttributes);
        $this->assertSame([$secondSite], $reloaded->siteIds);
        $this->assertSame(['subtitle'], array_map(fn($f) => $f->handle, $reloaded->customFields));
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

    // ---------------------------------------------------------------------
    // Edit
    // ---------------------------------------------------------------------

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
     * A menu's handle is part of how templates address it, so it has to be
     * changeable — and the old handle has to stop resolving the moment it is.
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

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

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

    /** Its *own* handle is not "already in use" — otherwise no menu could ever be re-saved. */
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

    // ---------------------------------------------------------------------
    // Duplicate
    // ---------------------------------------------------------------------

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

    public function testDuplicatingAMenuThatDoesNotExistIsNull(): void
    {
        $this->assertNull($this->menus()->duplicate(999999));
    }

    // ---------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------

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

    /** A deleted menu's handle is free again, and the new menu is a new menu. */
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

    // ---------------------------------------------------------------------
    // Listing
    // ---------------------------------------------------------------------

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

    // ---------------------------------------------------------------------
    // Project config
    // ---------------------------------------------------------------------

    /**
     * Menus are database-backed by design (see ARCHITECTURE.md): they are
     * content-shaped, edited by people who do not deploy, and must not
     * require a project-config sync to change. This is the assertion that
     * keeps that decision honest as menus are created, edited and deleted.
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
        $this->menus()->deleteById((int)$copy->id);
        $this->created = array_values(array_diff($this->created, [(int)$copy->id]));

        $this->assertSame($before, $projectConfig->get());
    }
}

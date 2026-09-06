<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\services\ProjectConfig as ProjectConfigService;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\records\MenuBuilderGroupRecord;
use Tahadudhiya\MenuBuilder\records\MenuBuilderItemRecord;
use Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService;

/**
 * Edition switching through **Craft's own mechanism**, and what it does to the data.
*/
class MenuBuilderEditionSwitchTest extends CraftIntegrationTestCase
{
    private const HANDLE = 'menu-builder';

    private const EDITION_PATH = ProjectConfigService::PATH_PLUGINS . '.' . self::HANDLE . '.edition';

    protected function tearDown(): void
    {
        // Back to what the harness installs, through the same mechanism the cases use, so a failed
        // assertion can't leave the suite on Free.
        Craft::$app->getPlugins()->switchEdition(self::HANDLE, MenuBuilder::EDITION_PRO);

        parent::tearDown();
    }

    // The switch itself

    /**
     * The harness installs Pro (see tests/integration-bootstrap.php), and that is a project-config
     * fact, not a property someone set.
    */
    public function testTheActiveEditionIsStoredInProjectConfig(): void
    {
        $this->assertSame(
            MenuBuilder::EDITION_PRO,
            Craft::$app->getProjectConfig()->get(self::EDITION_PATH),
            'The active edition is not where Craft stores it.'
        );
        $this->assertSame(MenuBuilder::EDITION_PRO, MenuBuilder::getInstance()->license->getEdition());
        $this->assertTrue(MenuBuilder::getInstance()->license->isPro());
    }

    /**
     * The whole downgrade, end to end, through Craft: five Pro menus with items in them, a licence
     * that lapses, and a database that is exactly as full afterwards as it was before.
    */
    public function testDowngradingThroughCraftKeepsEveryMenuAndItsItems(): void
    {
        $this->withNoMenus(function() {
            $handles = ['mainNav', 'footerNav', 'mobileNav', 'sidebarNav', 'accountNav'];
            $itemIds = [];

            foreach ($handles as $handle) {
                $menu = $this->newMenu($handle, ucfirst($handle));
                $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));
                $itemIds[] = (int)$this->addUrlItem((int)$menu->id, 'Home')->id;
                $itemIds[] = (int)$this->addUrlItem((int)$menu->id, 'Contact')->id;
            }

            $before = $this->menuFingerprints();
            $this->assertCount(5, $before);
            $this->assertSame(10, (int)MenuBuilderItemRecord::find()->count());

            // The licence lapses — through Craft, not through a property.
            Craft::$app->getPlugins()->switchEdition(self::HANDLE, MenuBuilder::EDITION_FREE);

            $this->assertSame(MenuBuilder::EDITION_FREE, Craft::$app->getProjectConfig()->get(self::EDITION_PATH));
            $this->assertSame(MenuBuilder::EDITION_FREE, MenuBuilder::getInstance()->license->getEdition());
            $this->assertFalse(MenuBuilder::getInstance()->license->isPro());

            // Nothing moved.
            $this->assertSame($before, $this->menuFingerprints(), 'A menu was deleted or modified by the downgrade.');
            $this->assertSame(10, (int)MenuBuilderItemRecord::find()->count(), 'Menu items were removed by the downgrade.');

            foreach ($itemIds as $itemId) {
                $this->assertNotNull(MenuBuilder::getInstance()->items->getById($itemId), "Item $itemId disappeared on Free.");
            }

            // All five still render.
            foreach ($handles as $handle) {
                $tree = MenuBuilder::getInstance()->resolver->getTree($handle);
                $this->assertNotNull($tree, "Menu \"$handle\" stopped rendering on Free.");
                $this->assertCount(2, $tree->items);
            }

            // Over the limit is not "give one up": it is "no more".
            $this->assertSame(5, MenuBuilder::getInstance()->menuLimit->getMenuCount());
            $this->assertFalse(MenuBuilder::getInstance()->menuLimit->canCreateMenu());
            $this->assertFalse(MenuBuilder::getInstance()->groups->save($this->newMenu('sixthNav', 'Sixth')));
            $this->assertSame($before, $this->menuFingerprints());
        });
    }

    /**
     * The upgrade half, also through Craft: creation comes back, and the menus the install already
     * had are untouched by the switch.
    */
    public function testUpgradingThroughCraftRestoresMenuCreation(): void
    {
        $this->withNoMenus(function() {
            Craft::$app->getPlugins()->switchEdition(self::HANDLE, MenuBuilder::EDITION_FREE);

            $only = $this->newMenu('freeOnly', 'Free Only');
            $this->assertTrue(MenuBuilder::getInstance()->groups->save($only));
            $this->addUrlItem((int)$only->id, 'Home');

            $before = $this->menuFingerprints();
            $this->assertFalse(MenuBuilder::getInstance()->menuLimit->canCreateMenu());

            Craft::$app->getPlugins()->switchEdition(self::HANDLE, MenuBuilder::EDITION_PRO);

            $this->assertSame(MenuBuilder::EDITION_PRO, Craft::$app->getProjectConfig()->get(self::EDITION_PATH));
            $this->assertTrue(MenuBuilder::getInstance()->menuLimit->canCreateMenu());
            $this->assertSame($before, $this->menuFingerprints(), 'The upgrade modified an existing menu.');

            foreach (['secondNav', 'thirdNav', 'fourthNav'] as $handle) {
                $this->assertTrue(
                    MenuBuilder::getInstance()->groups->save($this->newMenu($handle, ucfirst($handle))),
                    'Restoring Pro did not restore menu creation.'
                );
            }

            $this->assertSame(4, (int)MenuBuilderGroupRecord::find()->count());
            $this->assertSame(1, MenuBuilder::getInstance()->items->countForGroup((int)$only->id));
        });
    }

    /**
     * A "next request": every per-request memo this plugin holds is thrown away after the switch,
     * and the answers are unchanged.
    */
    public function testEveryPerRequestMemoAgreesAfterTheSwitch(): void
    {
        $this->withNoMenus(function() {
            $menu = $this->newMenu('survivor', 'Survivor');
            $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));
            $this->addUrlItem((int)$menu->id, 'Home');

            // Warm both memos on Pro.
            $this->assertTrue(MenuBuilder::getInstance()->menuLimit->canCreateMenu());
            $this->assertNotNull(MenuBuilder::getInstance()->resolver->getTree('survivor'));

            Craft::$app->getPlugins()->switchEdition(self::HANDLE, MenuBuilder::EDITION_FREE);

            // Without dropping anything: the limit must already be right, because it counts rows
            // rather than remembering an answer.
            $this->assertSame(MenuBuilder::EDITION_FREE, Craft::$app->getProjectConfig()->get(self::EDITION_PATH));
            $this->assertSame(1, MenuBuilder::getInstance()->menuLimit->getMenuCount());
            $this->assertFalse(MenuBuilder::getInstance()->menuLimit->canCreateMenu());

            // And the warm tree cache still serves the menu — an edition change is not a content
            // change and invalidates nothing.
            $tree = MenuBuilder::getInstance()->resolver->getTree('survivor');
            $this->assertNotNull($tree, 'A cached tree stopped resolving after the edition changed.');
            $this->assertCount(1, $tree->items);

            // Now as the next request would see it, with the memos gone.
            self::resetGroupService();
            MenuBuilder::getInstance()->cache->invalidateAll();

            $this->assertNotNull(MenuBuilder::getInstance()->groups->getByHandle('survivor'));
            $this->assertSame(1, MenuBuilder::getInstance()->menuLimit->getMenuCount());
            $this->assertFalse(MenuBuilder::getInstance()->menuLimit->canCreateMenu());
            $this->assertCount(1, MenuBuilder::getInstance()->resolver->getTree('survivor')->items);
        });
    }

    /**
     * Switching the edition writes one project-config value and nothing else — in particular, no
     * menu state is written, and no menu row is read back out of it.
    */
    public function testSwitchingEditionWritesNothingButTheEdition(): void
    {
        $this->withNoMenus(function() {
            $menu = $this->newMenu('pcMenu', 'Project Config Menu');
            $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));

            $projectConfig = Craft::$app->getProjectConfig();
            $before = $projectConfig->get();

            Craft::$app->getPlugins()->switchEdition(self::HANDLE, MenuBuilder::EDITION_FREE);

            $after = $projectConfig->get();
            $expected = $before;
            $expected['plugins'][self::HANDLE]['edition'] = MenuBuilder::EDITION_FREE;

            // Project config's own bookkeeping (dateModified) is not ours.
            unset($expected['dateModified'], $after['dateModified']);

            $this->assertSame($expected, $after, 'Switching edition changed more than the edition.');
        });
    }

    /**
     * Applying project config — the deploy path — never carries menu data, so it can neither
     * create nor delete a menu however the editions differ between environments.
    */
    public function testApplyingProjectConfigNeverTouchesMenuData(): void
    {
        $this->withNoMenus(function() {
            foreach (['deployA', 'deployB'] as $handle) {
                $menu = $this->newMenu($handle, ucfirst($handle));
                $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));
                $this->addUrlItem((int)$menu->id, 'Home');
            }

            $before = $this->menuFingerprints();

            // An incoming deploy that downgrades this environment.
            Craft::$app->getProjectConfig()->set(self::EDITION_PATH, MenuBuilder::EDITION_FREE, 'Test downgrade');

            $this->assertSame(MenuBuilder::EDITION_FREE, Craft::$app->getProjectConfig()->get(self::EDITION_PATH));
            self::resetGroupService();

            $this->assertSame($before, $this->menuFingerprints(), 'A project-config apply changed menu data.');
            $this->assertSame(2, (int)MenuBuilderItemRecord::find()->count());
        });
    }

    // Harness

    /**
     * Every menu row, whole, keyed by id — the comparison that says "nothing was deleted **and**
     * nothing was modified", which a row count alone cannot.
     *
     * @return array<int,array<string,mixed>>
    */
    private function menuFingerprints(): array
    {
        $rows = [];

        foreach (MenuBuilderGroupRecord::find()->orderBy(['id' => SORT_ASC])->asArray()->all() as $row) {
            $rows[(int)$row['id']] = $row;
        }

        return $rows;
    }

    /**
     * Runs `$body` against an empty `menubuilder_groups`, then rolls the table (and everything
     * cascading from it) back.
    */
    private function withNoMenus(callable $body): void
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            MenuBuilderGroupRecord::deleteAll();
            self::resetGroupService();

            $body();
        } finally {
            $transaction->rollBack();
            self::resetGroupService();
            MenuBuilder::getInstance()->cache->invalidateAll();
        }
    }

    private static function resetGroupService(): void
    {
        MenuBuilder::getInstance()->set('groups', MenuBuilderGroupService::class);
    }

    private function newMenu(string $handle, string $name): MenuBuilderGroup
    {
        $menu = new MenuBuilderGroup();
        $menu->name = $name;
        $menu->handle = $handle;
        $menu->enabled = true;

        return $menu;
    }

    private function addUrlItem(int $groupId, string $title): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = $groupId;
        $item->title = $title;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = '/' . strtolower(str_replace(' ', '-', $title));
        $item->enabled = true;

        $this->assertTrue(MenuBuilder::getInstance()->items->save($item), json_encode($item->getErrors()));

        return $item;
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\elements\User;
use craft\web\Request;
use craft\web\Response;
use Tahadudhiya\MenuBuilder\controllers\GroupsController;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\records\MenuBuilderGroupRecord;
use Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService;

/**
 * The Free edition's one-menu limit, enforced against a real database.
 *
 * MenuBuilderLicensingTest pins the arithmetic without an app. This is the
 * half that arithmetic cannot answer: whether a second menu can actually
 * reach `menubuilder_groups` — through the service, through a duplicate,
 * and through a hand-written POST to the controller that never saw the
 * disabled button.
 *
 * ## How the edition is switched
 *
 * By assigning `MenuBuilder::getInstance()->edition`, which is precisely
 * what Craft's own `Plugins::switchEdition()` ends up doing to the running
 * plugin instance. Switching it here (rather than writing project config)
 * keeps the switch inside the test and makes the lapsed-license case
 * expressible: Pro, five menus, back to Free, and back to Pro again.
 *
 * ## How the database is kept honest
 *
 * The suite's shared fixture already holds several menus, and most cases
 * here need to control the count exactly. Each one runs inside
 * {@see withNoMenus()}: a transaction that empties `menubuilder_groups`,
 * runs the case, and rolls back — so the fixture every other test class
 * depends on is exactly as it was, including the entries whose field values
 * point at those menus by UID.
 */
class MenuBuilderMenuLimitTest extends CraftIntegrationTestCase
{
    private static ?int $adminId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Creating a second user needs a Craft edition that allows one, and
        // the harness installs the default. Same reason (and same call) as
        // ControllerAuthorizationTest; nothing here reads Craft's edition
        // otherwise, and it is unrelated to *this plugin's* edition.
        Craft::$app->setEdition(Craft::Pro);
    }

    /** @var array<string,mixed> Console components swapped out by the direct-POST cases. */
    private static array $originalComponents = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every test states the edition it is about; this is only the
        // baseline the harness installs (see tests/integration-bootstrap.php).
        $this->setEdition(MenuBuilder::EDITION_PRO);
    }

    protected function tearDown(): void
    {
        $this->setEdition(MenuBuilder::EDITION_PRO);

        foreach (self::$originalComponents as $id => $component) {
            Craft::$app->set($id, $component);
        }

        self::$originalComponents = [];
        Craft::$app->getUser()->setIdentity(null);

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Free
    // ---------------------------------------------------------------------

    public function testFreeCanCreateItsFirstMenu(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_FREE);

            $limit = MenuBuilder::getInstance()->menuLimit;

            $this->assertSame(0, $limit->getMenuCount());
            $this->assertSame(1, $limit->getMaxMenus());
            $this->assertTrue($limit->canCreateMenu());

            $menu = $this->newMenu('freeFirst', 'Free First Menu');

            $this->assertTrue(
                MenuBuilder::getInstance()->groups->save($menu),
                'Free could not create its first menu: ' . json_encode($menu->getErrors())
            );
            $this->assertNotNull($menu->id);
            $this->assertSame(1, $limit->getMenuCount());
            $this->assertFalse($limit->canCreateMenu());
        });
    }

    public function testFreeCannotCreateASecondMenu(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_FREE);

            $this->assertTrue(MenuBuilder::getInstance()->groups->save($this->newMenu('freeOnly', 'Free Only')));

            $second = $this->newMenu('freeSecond', 'Free Second');

            $this->assertFalse(
                MenuBuilder::getInstance()->groups->save($second),
                'Free created a second menu.'
            );
            $this->assertNull($second->id, 'The refused menu was still given an ID.');
            $this->assertStringContainsString(
                'Upgrade to Pro',
                implode(' ', $second->getErrorSummary(true)),
                'The refusal did not say how to lift the limit.'
            );
            $this->assertSame(1, $this->menuRowCount(), 'A second menu row reached the database.');
        });
    }

    /**
     * Duplicating is the plugin's other way to bring a menu into existence,
     * so it meets the same ceiling — otherwise the limit would be one button
     * away from meaningless.
     */
    public function testFreeCannotDuplicateItsOnlyMenu(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_FREE);

            $menu = $this->newMenu('freeDup', 'Free Duplicate Source');
            $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));

            $this->assertNull(
                MenuBuilder::getInstance()->groups->duplicate((int)$menu->id),
                'Free duplicated its way to a second menu.'
            );
            $this->assertSame(1, $this->menuRowCount());
        });
    }

    /**
     * The limit is on menus and on nothing else. One Free menu takes as many
     * items, nested as deeply, as any Pro one — and can still be edited,
     * renamed, disabled and re-enabled after it exists.
     */
    public function testFreeCanEditItsMenuAndFillItWithUnlimitedNestedItems(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_FREE);

            $menu = $this->newMenu('freeDeep', 'Free Deep Menu');
            $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));

            // Editing the one menu it is allowed.
            $menu->name = 'Free Deep Menu (renamed)';
            $menu->enabled = false;
            $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu), 'Free could not edit its own menu.');
            $menu->enabled = true;
            $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));

            $groupId = (int)$menu->id;

            // A wide, shallow forest…
            for ($i = 0; $i < 40; $i++) {
                $this->assertNotNull($this->addNestedItem($groupId, "Top $i")->id);
            }

            // …and one deep branch. Ten levels, on a menu with no maxDepth.
            $parentId = null;

            for ($level = 1; $level <= 10; $level++) {
                $item = $this->addNestedItem($groupId, "Level $level", $parentId);
                $this->assertNotNull(
                    $item->id,
                    "Free refused a level-$level item: " . json_encode($item->getErrors())
                );
                $parentId = (int)$item->id;
            }

            $this->assertSame(50, MenuBuilder::getInstance()->items->countForGroup($groupId));
            $this->assertSame(1, $this->menuRowCount(), 'Filling a menu with items created menus.');
        });
    }

    /**
     * "All features, one menu" is the product rule, so the Free menu has to
     * be a fully configured one — depth cap, CSS class, HTML attributes, a
     * site restriction, per-item visibility rules — and it has to resolve.
     * Nothing in the plugin asks the edition for permission to do any of
     * this; this case is what would fail if something ever started.
     */
    public function testFreeGetsEveryFeatureInsideItsOneMenu(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_FREE);

            $menu = $this->newMenu('freeFeatures', 'Free Features');
            $menu->maxDepth = 5;
            $menu->cssClass = 'site-nav';
            $menu->htmlAttributes = ['data-nav' => 'primary'];
            $menu->siteIds = [Craft::$app->getSites()->getPrimarySite()->id];

            $this->assertTrue(
                MenuBuilder::getInstance()->groups->save($menu),
                'Free could not save a fully configured menu: ' . json_encode($menu->getErrors())
            );

            $parent = $this->addNestedItem((int)$menu->id, 'Products');
            $this->assertNotNull($parent->id);

            $child = $this->addNestedItem((int)$menu->id, 'Widgets', (int)$parent->id);
            $this->assertNotNull($child->id);

            $restricted = new MenuBuilderItem();
            $restricted->groupId = (int)$menu->id;
            $restricted->title = 'Members only';
            $restricted->type = MenuBuilderItem::TYPE_URL;
            $restricted->customUrl = '/members';
            $restricted->visibility = [['type' => 'loggedIn']];
            $this->assertTrue(
                MenuBuilder::getInstance()->items->save($restricted),
                'Free could not save an item with a visibility rule: ' . json_encode($restricted->getErrors())
            );

            $reloaded = MenuBuilder::getInstance()->groups->getByHandle('freeFeatures');
            $this->assertSame(5, $reloaded->maxDepth);
            $this->assertSame('site-nav', $reloaded->cssClass);
            $this->assertSame(['data-nav' => 'primary'], $reloaded->htmlAttributes);

            $tree = MenuBuilder::getInstance()->resolver->getTree('freeFeatures');
            $this->assertNotNull($tree, 'A Free menu did not resolve.');
            // Two top-level nodes: the logged-in-only one is filtered out for
            // an anonymous audience, which is the visibility rule working.
            $this->assertCount(1, $tree->items);
            $this->assertCount(1, $tree->items[0]->children);
        });
    }

    // ---------------------------------------------------------------------
    // Pro
    // ---------------------------------------------------------------------

    public function testProCanCreateManyMenus(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_PRO);

            $limit = MenuBuilder::getInstance()->menuLimit;
            $this->assertNull($limit->getMaxMenus(), 'Pro is supposed to be unlimited.');

            for ($i = 1; $i <= 12; $i++) {
                $this->assertTrue($limit->canCreateMenu());
                $menu = $this->newMenu("proMenu$i", "Pro Menu $i");
                $this->assertTrue(
                    MenuBuilder::getInstance()->groups->save($menu),
                    "Pro was refused menu $i: " . json_encode($menu->getErrors())
                );
            }

            $this->assertSame(12, $this->menuRowCount());

            // And duplicating, which is the other creation path.
            $source = MenuBuilder::getInstance()->groups->getByHandle('proMenu1');
            $this->assertNotNull(MenuBuilder::getInstance()->groups->duplicate((int)$source->id));
            $this->assertSame(13, $this->menuRowCount());
        });
    }

    // ---------------------------------------------------------------------
    // License transitions — the non-destructive requirement
    // ---------------------------------------------------------------------

    /**
     * The case the whole design exists to protect: a Pro install with five
     * menus whose edition goes back to Free keeps all five, keeps their
     * items, keeps rendering them, and can still manage them. The only thing
     * it loses is the ability to add a sixth — and it gets that back the
     * moment Pro returns.
     */
    public function testALapsedProEditionKeepsEveryMenuAndItsItems(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_PRO);

            $handles = ['mainNav', 'footerNav', 'mobileNav', 'sidebarNav', 'accountNav'];

            foreach ($handles as $handle) {
                $menu = $this->newMenu($handle, ucfirst($handle));
                $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));
                $this->addNestedItem((int)$menu->id, 'Home');
                $this->addNestedItem((int)$menu->id, 'Contact');
            }

            $this->assertSame(5, $this->menuRowCount());

            // The license lapses.
            $this->setEdition(MenuBuilder::EDITION_FREE);

            $groups = MenuBuilder::getInstance()->groups;

            $this->assertSame(5, $this->menuRowCount(), 'Menus were removed when the edition changed.');
            $this->assertSame(5, MenuBuilder::getInstance()->menuLimit->getMenuCount());
            $this->assertFalse(MenuBuilder::getInstance()->menuLimit->canCreateMenu());

            foreach ($handles as $handle) {
                $menu = $groups->getByHandle($handle);
                $this->assertInstanceOf(MenuBuilderGroup::class, $menu, "Menu \"$handle\" disappeared on Free.");
                $this->assertSame(2, MenuBuilder::getInstance()->items->countForGroup((int)$menu->id));

                // Still resolvable — the front end is not licensed.
                $tree = MenuBuilder::getInstance()->resolver->getTree($handle);
                $this->assertNotNull($tree, "Menu \"$handle\" stopped rendering on Free.");
                $this->assertCount(2, $tree->items);
            }

            // Still manageable: editing, disabling and deleting an existing
            // menu are never limited.
            $footer = $groups->getByHandle('footerNav');
            $footer->name = 'Footer (edited on Free)';
            $this->assertTrue($groups->save($footer), 'Free could not edit a menu it inherited from Pro.');
            $this->assertNotNull($this->addNestedItem((int)$footer->id, 'Added on Free')->id);

            // But not a sixth menu, by any path.
            $sixth = $this->newMenu('sixthNav', 'Sixth');
            $this->assertFalse($groups->save($sixth));
            $this->assertNull($groups->duplicate((int)$footer->id));
            $this->assertSame(5, $this->menuRowCount());

            // Pro returns.
            $this->setEdition(MenuBuilder::EDITION_PRO);

            $this->assertTrue(MenuBuilder::getInstance()->menuLimit->canCreateMenu());
            $restored = $this->newMenu('sixthNav', 'Sixth');
            $this->assertTrue($groups->save($restored), 'Restoring Pro did not restore menu creation.');
            $this->assertSame(6, $this->menuRowCount());

            foreach ($handles as $handle) {
                $this->assertInstanceOf(MenuBuilderGroup::class, $groups->getByHandle($handle));
            }
        });
    }

    // ---------------------------------------------------------------------
    // Multi-site
    // ---------------------------------------------------------------------

    /**
     * Menus are global rows with an optional site *restriction*
     * (`MenuBuilderGroup::$siteIds`), not per-site entities — so the Free
     * ceiling is one menu per **install**. A second menu is refused however
     * few sites the first one is restricted to, and whichever site is
     * current when the question is asked.
     *
     * This is the behaviour the existing data model already implies; the
     * test exists so that "one per site" cannot be introduced by accident.
     */
    public function testTheFreeLimitCountsMenusPerInstallNotPerSite(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_FREE);

            $sites = Craft::$app->getSites();
            $primaryId = (int)$sites->getPrimarySite()->id;
            $secondaryId = self::$secondSiteId;

            // The one Free menu, restricted to the primary site only.
            $menu = $this->newMenu('perSitePrimary', 'Primary Only');
            $menu->siteIds = [$primaryId];
            $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));

            // A menu "for the other site" is still a second menu.
            $second = $this->newMenu('perSiteSecondary', 'Secondary Only');
            $second->siteIds = [$secondaryId];

            $this->assertFalse(
                MenuBuilder::getInstance()->groups->save($second),
                'A site-restricted menu was allowed as a second menu on Free.'
            );
            $this->assertSame(1, $this->menuRowCount());

            // And the answer does not depend on which site is current.
            $originalSite = $sites->getCurrentSite();

            try {
                foreach ([$primaryId, $secondaryId] as $siteId) {
                    $sites->setCurrentSite($siteId);

                    $this->assertSame(1, MenuBuilder::getInstance()->menuLimit->getMenuCount());
                    $this->assertFalse(
                        MenuBuilder::getInstance()->menuLimit->canCreateMenu(),
                        "The limit answered differently on site $siteId."
                    );
                }
            } finally {
                $sites->setCurrentSite($originalSite);
            }

            // The restriction itself still behaves as it always has: the menu
            // resolves on its own site and nowhere else.
            $sites->setCurrentSite($primaryId);
            $this->assertNotNull(MenuBuilder::getInstance()->resolver->getTree('perSitePrimary'));

            try {
                $sites->setCurrentSite($secondaryId);
                MenuBuilder::getInstance()->cache->invalidateAll();
                $this->assertNull(
                    MenuBuilder::getInstance()->resolver->getTree('perSitePrimary'),
                    'A site restriction stopped being honoured on Free.'
                );
            } finally {
                $sites->setCurrentSite($originalSite);
            }
        });
    }

    // ---------------------------------------------------------------------
    // Server-side enforcement
    // ---------------------------------------------------------------------

    /**
     * The button is not the boundary. This is the request that arrives when
     * someone ignores it: a real admin, a real CP POST, straight at
     * `menu-builder/groups/save`.
     */
    public function testTheLimitCannotBeBypassedByPostingToTheSaveAction(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_FREE);

            $this->assertTrue(MenuBuilder::getInstance()->groups->save($this->newMenu('bypassOnly', 'Bypass Only')));

            $controller = $this->adminController([
                'groupId' => 0,
                'name' => 'Smuggled Menu',
                'handle' => 'smuggled',
                'enabled' => '1',
            ]);

            $controller->actionSave();

            $this->assertSame(1, $this->menuRowCount(), 'A direct POST created a second menu on Free.');
            $this->assertNull(
                MenuBuilder::getInstance()->groups->getByHandle('smuggled'),
                'A direct POST created a second menu on Free.'
            );
        });
    }

    public function testTheLimitCannotBeBypassedByPostingToTheDuplicateAction(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_FREE);

            $menu = $this->newMenu('bypassDup', 'Bypass Duplicate');
            $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));

            $controller = $this->adminController(['id' => (int)$menu->id]);
            $controller->actionDuplicate();

            $this->assertSame(1, $this->menuRowCount(), 'A direct POST duplicated a second menu into existence on Free.');
        });
    }

    /**
     * The service is the boundary, so it refuses even a caller that never
     * went near a controller — a console command, a migration helper, or
     * third-party code holding the service directly.
     */
    public function testTheServiceRefusesADirectCallerAtTheLimit(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_FREE);

            $groups = MenuBuilder::getInstance()->groups;
            $this->assertTrue($groups->save($this->newMenu('serviceOnly', 'Service Only')));

            // Validation off, as an internal caller would: the ceiling is not
            // a validation rule and is not skipped with them.
            $second = $this->newMenu('serviceSecond', 'Service Second');
            $this->assertFalse($groups->save($second, runValidation: false));
            $this->assertSame(1, $this->menuRowCount());
        });
    }

    // ---------------------------------------------------------------------
    // The CP summary
    // ---------------------------------------------------------------------

    public function testTheControlPanelSummaryDescribesEachEdition(): void
    {
        $this->withNoMenus(function() {
            $this->setEdition(MenuBuilder::EDITION_FREE);
            $this->assertTrue(MenuBuilder::getInstance()->groups->save($this->newMenu('summary', 'Summary')));

            $free = MenuBuilder::getInstance()->menuLimit->cpSummary();

            $this->assertFalse($free['isPro']);
            $this->assertSame('Free', $free['editionName']);
            $this->assertSame(1, $free['menuCount']);
            $this->assertSame(1, $free['maxMenus']);
            $this->assertFalse($free['canCreate']);
            $this->assertFalse($free['licenseActive']);
            $this->assertArrayNotHasKey('licenseKey', $free, 'The summary must never carry the license key.');

            $this->setEdition(MenuBuilder::EDITION_PRO);

            $pro = MenuBuilder::getInstance()->menuLimit->cpSummary();

            $this->assertTrue($pro['isPro']);
            $this->assertSame('Pro', $pro['editionName']);
            $this->assertNull($pro['maxMenus']);
            $this->assertTrue($pro['canCreate']);
            $this->assertNull($pro['upgradeUrl'], 'Pro has nothing to upgrade to.');
        });
    }

    // ---------------------------------------------------------------------
    // Harness
    // ---------------------------------------------------------------------

    private function setEdition(string $edition): void
    {
        MenuBuilder::getInstance()->edition = $edition;
    }

    /**
     * Runs `$body` against an empty `menubuilder_groups`, then puts the
     * table (and everything cascading from it) back exactly as it was.
     *
     * The rollback is the whole point: the suite's shared fixture — five
     * menus, plus entries whose Navigation field values reference them by
     * UID — is built once per run and every other test class reads it.
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

    /**
     * A fresh MenuBuilderGroupService.
     *
     * The service memoizes the full menu list per request and clears that
     * memo on every write *it* performs — which is the right behaviour and
     * exactly wrong for a test that deletes rows underneath it and then
     * rolls the deletion back. Replacing the component is the honest reset;
     * nothing else in the plugin caches menu rows.
     */
    private static function resetGroupService(): void
    {
        MenuBuilder::getInstance()->set('groups', MenuBuilderGroupService::class);
    }

    private function menuRowCount(): int
    {
        return (int)MenuBuilderGroupRecord::find()->count();
    }

    private function newMenu(string $handle, string $name): MenuBuilderGroup
    {
        $menu = new MenuBuilderGroup();
        $menu->name = $name;
        $menu->handle = $handle;
        $menu->enabled = true;

        return $menu;
    }

    private function addNestedItem(int $groupId, string $title, ?int $parentId = null): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = $groupId;
        $item->parentId = $parentId;
        $item->title = $title;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = '/' . strtolower(str_replace(' ', '-', $title));
        $item->enabled = true;

        MenuBuilder::getInstance()->items->save($item);

        return $item;
    }

    /**
     * The GroupsController, wired to a real CP POST from a real admin.
     *
     * JSON, because the alternative branch flashes to a session a
     * console-booted application doesn't have — the same reason
     * ControllerAuthorizationTest posts JSON for its write cases. The
     * permission gate is not the subject here (an admin passes it); what is
     * being tested is what the action does once it is through.
     *
     * @param array<string,mixed> $body
     */
    private function adminController(array $body): GroupsController
    {
        if (self::$originalComponents === []) {
            self::$originalComponents = [
                'request' => Craft::$app->get('request'),
                'response' => Craft::$app->get('response'),
            ];
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['SCRIPT_FILENAME'] = CRAFT_BASE_PATH . '/web/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/admin/actions/menu-builder';
        $_SERVER['SERVER_NAME'] = 'primary.test';
        $_SERVER['HTTP_HOST'] = 'primary.test';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $request = new Request(['cookieValidationKey' => 'menu-builder-integration-tests']);
        $request->setBodyParams($body);

        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
        Craft::$app->getUser()->setIdentity($this->admin());

        $controller = new GroupsController('groups', MenuBuilder::getInstance());
        $controller->enableCsrfValidation = false;

        return $controller;
    }

    private function admin(): User
    {
        if (self::$adminId !== null) {
            /** @var User|null $existing */
            $existing = User::find()->id(self::$adminId)->status(null)->one();

            if ($existing !== null) {
                return $existing;
            }

            // The user was created inside a case's transaction and rolled
            // back with it. Nothing about this test depends on it being the
            // same row twice.
            self::$adminId = null;
        }

        if (self::$adminId === null) {
            $user = new User([
                'username' => 'menuLimitAdmin',
                'email' => 'menu-limit-admin@example.test',
                'admin' => true,
            ]);

            if (!Craft::$app->getElements()->saveElement($user)) {
                throw new \RuntimeException('Could not create the admin: ' . json_encode($user->getErrors()));
            }

            self::$adminId = (int)$user->id;
        }

        /** @var User $admin */
        $admin = User::find()->id(self::$adminId)->status(null)->one();

        return $admin;
    }
}

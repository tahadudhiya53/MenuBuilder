<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\helpers\StringHelper;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\fields\MenuBuilderField;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderFieldHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;

/**
 * What this plugin does and does not put in project config.
 *
 * MenuBuilder deliberately splits its state in two (see ARCHITECTURE.md):
 *
 * - **Menus and their items are database-backed.** They are content, edited
 *   by people who do not deploy, and must never require a project-config
 *   sync — nor turn a menu edit into a file change on a production install
 *   with `allowAdminChanges` off.
 * - **The Navigation field is a field**, so its definition and settings ride
 *   in project config like every other Craft field, and deploy from one
 *   environment to the next.
 *
 * A test suite that only checks "no project config is written" would pass
 * just as happily if the field stopped deploying, so both halves are asserted
 * here — including a real *deploy*: project config written on one environment
 * and applied on another.
 */
class MenuBuilderProjectConfigTest extends TestCase
{
    private const PATH = 'fields';

    /** @var string[] Field handles created by the running test. */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $handle) {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);

            if ($field) {
                Craft::$app->getFields()->deleteField($field);
            }
        }

        $this->created = [];

        parent::tearDown();
    }

    private function makeField(string $handle, array $allowedGroupUids = []): MenuBuilderField
    {
        $field = new MenuBuilderField([
            'name' => ucfirst($handle),
            'handle' => $handle,
            'allowedGroupUids' => $allowedGroupUids,
        ]);

        $this->assertTrue(Craft::$app->getFields()->saveField($field), json_encode($field->getErrors()));
        $this->created[] = $handle;

        return $field;
    }

    private function configFor(MenuBuilderField $field): ?array
    {
        return Craft::$app->getProjectConfig()->get(self::PATH . '.' . $field->uid);
    }

    private function handle(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(4));
    }

    // ---------------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------------

    public function testSavingTheFieldWritesItToProjectConfig(): void
    {
        $handle = $this->handle('navPc');
        $field = $this->makeField($handle);

        $config = $this->configFor($field);

        $this->assertNotNull($config);
        $this->assertSame($handle, $config['handle']);
        $this->assertSame(MenuBuilderField::class, $config['type']);
    }

    /**
     * The allow list is stored as menu **UIDs** rather than IDs precisely so
     * it can deploy: auto-increment IDs differ per environment, UIDs do not.
     */
    public function testTheAllowListIsStoredAsUidsSoItSurvivesADeploy(): void
    {
        $menu = $this->menu();
        $field = $this->makeField($this->handle('navAllow'), [(string)$menu->uid]);

        $config = $this->configFor($field);

        $this->assertSame([(string)$menu->uid], $config['settings']['allowedGroupUids']);
        $this->assertStringNotContainsString('"id"', json_encode($config['settings']));

        MenuBuilder::getInstance()->groups->deleteById((int)$menu->id);
    }

    // ---------------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------------

    public function testEditingTheFieldUpdatesItsProjectConfigEntryInPlace(): void
    {
        $menu = $this->menu();
        $field = $this->makeField($this->handle('navEdit'));

        // An empty allow list is stored as nothing rather than as an empty
        // array — "no restriction" is the absence of the setting.
        $this->assertSame([], $this->configFor($field)['settings']['allowedGroupUids'] ?? []);

        $field->allowedGroupUids = [(string)$menu->uid];
        $field->name = 'Renamed';
        $this->assertTrue(Craft::$app->getFields()->saveField($field));

        $config = $this->configFor($field);
        $this->assertSame('Renamed', $config['name']);
        $this->assertSame([(string)$menu->uid], $config['settings']['allowedGroupUids']);

        MenuBuilder::getInstance()->groups->deleteById((int)$menu->id);
    }

    // ---------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------

    public function testDeletingTheFieldRemovesItFromProjectConfig(): void
    {
        $handle = $this->handle('navGone');
        $field = $this->makeField($handle);
        $uid = (string)$field->uid;

        $this->assertTrue(Craft::$app->getFields()->deleteField($field));
        $this->created = array_values(array_diff($this->created, [$handle]));

        $this->assertNull(Craft::$app->getProjectConfig()->get(self::PATH . '.' . $uid));
        $this->assertNull(Craft::$app->getFields()->getFieldByHandle($handle));
    }

    // ---------------------------------------------------------------------
    // Deploy
    // ---------------------------------------------------------------------

    /**
     * A deploy is project config arriving from *outside* — written by another
     * environment and applied here, with no `saveField()` call involved. If
     * the field could only be created through its own save path, it would
     * never appear on a production install.
     */
    public function testAFieldArrivingFromAnotherEnvironmentIsInstalledByApplyingProjectConfig(): void
    {
        $handle = $this->handle('navDeployed');
        $uid = StringHelper::UUID();
        $menu = $this->menu();

        Craft::$app->getProjectConfig()->set(self::PATH . '.' . $uid, [
            'name' => 'Deployed Navigation',
            'handle' => $handle,
            'type' => MenuBuilderField::class,
            'instructions' => null,
            'searchable' => false,
            'translationMethod' => MenuBuilderField::TRANSLATION_METHOD_NONE,
            'translationKeyFormat' => null,
            'settings' => [
                'allowedGroupUids' => [(string)$menu->uid],
            ],
        ]);

        $this->created[] = $handle;

        /** @var MenuBuilderField|null $field */
        $field = Craft::$app->getFields()->getFieldByHandle($handle);

        $this->assertInstanceOf(MenuBuilderField::class, $field);
        $this->assertSame($uid, (string)$field->uid);
        $this->assertSame([(string)$menu->uid], $field->allowedGroupUids);

        MenuBuilder::getInstance()->groups->deleteById((int)$menu->id);
    }

    /**
     * The allow list names a menu by UID, and menus do not deploy — so on the
     * receiving environment the UID may name a menu that is not there yet.
     * That has to leave a usable field rather than a broken one.
     */
    public function testAnAllowListNamingAMenuThatDoesNotExistHereStillInstalls(): void
    {
        $handle = $this->handle('navUnknown');
        $uid = StringHelper::UUID();

        Craft::$app->getProjectConfig()->set(self::PATH . '.' . $uid, [
            'name' => 'Deployed Navigation',
            'handle' => $handle,
            'type' => MenuBuilderField::class,
            'instructions' => null,
            'searchable' => false,
            'translationMethod' => MenuBuilderField::TRANSLATION_METHOD_NONE,
            'translationKeyFormat' => null,
            'settings' => [
                'allowedGroupUids' => [StringHelper::UUID()],
            ],
        ]);

        $this->created[] = $handle;

        /** @var MenuBuilderField $field */
        $field = Craft::$app->getFields()->getFieldByHandle($handle);

        $this->assertInstanceOf(MenuBuilderField::class, $field);
        // The picker offers nothing rather than erroring: the allow list is
        // satisfied by no menu on this environment.
        $this->assertSame([], MenuBuilderFieldHelper::selectableGroups(
            MenuBuilder::getInstance()->groups->getAll(),
            $field->allowedGroupUids,
            includeDisabled: false,
        ));
    }

    public function testRemovingTheFieldFromProjectConfigUninstallsIt(): void
    {
        $handle = $this->handle('navRemoved');
        $field = $this->makeField($handle);

        Craft::$app->getProjectConfig()->remove(self::PATH . '.' . $field->uid);
        $this->created = array_values(array_diff($this->created, [$handle]));

        $this->assertNull(Craft::$app->getFields()->getFieldByHandle($handle));
    }

    // ---------------------------------------------------------------------
    // What must never be in project config
    // ---------------------------------------------------------------------

    /**
     * The plugin registers no project-config event handlers of its own for
     * menus, and no menu lifecycle operation may add one — asserted here
     * against the whole config rather than against a path, so a handler added
     * under any name is caught.
     */
    public function testNoMenuStateEverReachesProjectConfig(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $before = $projectConfig->get();

        $menu = $this->menu();
        $item = new \Tahadudhiya\MenuBuilder\models\MenuBuilderItem();
        $item->groupId = (int)$menu->id;
        $item->title = 'Home';
        $item->type = \Tahadudhiya\MenuBuilder\models\MenuBuilderItem::TYPE_URL;
        $item->customUrl = '/';
        $this->assertTrue(MenuBuilder::getInstance()->items->save($item));

        MenuBuilder::getInstance()->items->deleteById((int)$item->id);
        MenuBuilder::getInstance()->groups->deleteById((int)$menu->id);

        $this->assertSame($before, $projectConfig->get());
    }

    private function menu(): MenuBuilderGroup
    {
        $menu = new MenuBuilderGroup();
        $menu->name = 'PC Menu';
        $menu->handle = $this->handle('pcMenu');

        $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));

        return $menu;
    }
}

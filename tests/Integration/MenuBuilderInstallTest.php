<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\db\Connection;
use craft\helpers\App;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\migrations\Install;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * The install migration, run for real.
 *
 * Until this existed, `src/migrations/Install.php` was only ever asserted
 * against as *text* — `file_get_contents()` plus `assertStringContainsString()`
 * — which cannot tell you whether the schema it describes actually builds,
 * whether the foreign keys cascade, or whether a second `safeUp()` on an
 * already-installed database is safe. Those are the three things an install
 * or an upgrade can get wrong, so they are asserted here against a real
 * MySQL schema.
 *
 * ## Why a table prefix
 *
 * The migration's table names are fixed, so running `safeDown()` against the
 * suite's own tables would drop the ones every other integration class is
 * using. This class therefore points a second `craft\db\Connection` at the
 * same database with a **table prefix of its own**, so `{{%menubuilder_groups}}`
 * resolves to a throwaway copy and the shared fixture is never touched. A
 * prefix rather than a second schema because the test database user is not
 * assumed to hold `CREATE DATABASE`.
 */
class MenuBuilderInstallTest extends TestCase
{
    private const GROUPS = '{{%menubuilder_groups}}';
    private const ITEMS = '{{%menubuilder_items}}';

    /** Keeps this class's copy of the schema clear of the suite's own tables. */
    private const PREFIX = 'mbinstall_';

    private static Connection $db;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // The app's own database config, so this connection is a real
        // craft\db\Connection with Craft's command and schema classes — the
        // migration calls `dropTableIfExists()`, which is Craft's, not Yii's.
        $config = App::dbConfig();
        $config['tablePrefix'] = self::PREFIX;

        /** @var Connection $db */
        $db = Craft::createObject($config);
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        (new Install(['db' => self::$db, 'compact' => true]))->safeDown();
        self::$db->close();

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Every test starts from an empty schema and installs into it, so no
        // test can depend on what another one left behind.
        $this->dropEverything();
        $this->assertTrue($this->migration()->safeUp());
        self::$db->schema->refresh();
    }

    private function migration(): Install
    {
        return new Install(['db' => self::$db, 'compact' => true]);
    }

    private function dropEverything(): void
    {
        self::$db->createCommand()->checkIntegrity(false)->execute();

        foreach (self::ownTables() as $table) {
            self::$db->createCommand()->dropTable($table)->execute();
        }

        self::$db->createCommand()->checkIntegrity(true)->execute();
        self::$db->schema->refresh();
    }

    // ---------------------------------------------------------------------
    // Fresh install
    // ---------------------------------------------------------------------

    public function testAFreshInstallCreatesBothTablesAndNothingElse(): void
    {
        $this->assertSame(
            [self::PREFIX . 'menubuilder_groups', self::PREFIX . 'menubuilder_items'],
            self::ownTables(),
        );
    }

    /**
     * Every attribute the group model persists needs a column to persist
     * into. The old test read this off the migration's source text; this one
     * asks the database what it built.
     *
     * @dataProvider groupColumnProvider
     */
    public function testEveryGroupColumnExists(string $column): void
    {
        $this->assertArrayHasKey(
            $column,
            self::$db->schema->getTableSchema(self::GROUPS, true)->columns,
        );
    }

    /** @return array<string,array{string}> */
    public static function groupColumnProvider(): array
    {
        $columns = [
            'id', 'name', 'handle', 'description', 'enabled', 'sortOrder', 'maxDepth',
            'cssClass', 'htmlAttributes', 'settings', 'fieldLayoutId',
            'dateCreated', 'dateUpdated', 'uid',
        ];

        return array_combine($columns, array_map(fn(string $c) => [$c], $columns));
    }

    public function testTheGroupIdIsAnAutoIncrementingPrimaryKey(): void
    {
        $id = self::$db->schema->getTableSchema(self::GROUPS, true)->columns['id'];

        $this->assertTrue($id->isPrimaryKey);
        $this->assertTrue($id->autoIncrement);
    }

    /**
     * Both JSON bags are read back and decoded on every load, so a NULL in
     * either is a decode error rather than an empty bag. The column, not the
     * model, is what has to refuse it.
     *
     * @dataProvider notNullBagProvider
     */
    public function testTheJsonBagsCannotBeNull(string $table, string $column): void
    {
        $this->assertFalse(
            self::$db->schema->getTableSchema($table, true)->columns[$column]->allowNull,
        );
    }

    /** @return array<string,array{string,string}> */
    public static function notNullBagProvider(): array
    {
        return [
            'group htmlAttributes' => [self::GROUPS, 'htmlAttributes'],
            'group settings' => [self::GROUPS, 'settings'],
            'item htmlAttributes' => [self::ITEMS, 'htmlAttributes'],
            'item visibility' => [self::ITEMS, 'visibility'],
            'item metadata' => [self::ITEMS, 'metadata'],
        ];
    }

    /** @dataProvider itemColumnProvider */
    public function testEveryItemColumnExists(string $column): void
    {
        $this->assertArrayHasKey(
            $column,
            self::$db->schema->getTableSchema(self::ITEMS, true)->columns,
        );
    }

    /** @return array<string,array{string}> */
    public static function itemColumnProvider(): array
    {
        $columns = [
            'id', 'groupId', 'parentId', 'type', 'title', 'handle', 'enabled', 'sortOrder',
            'clickable', 'elementId', 'customUrl', 'target', 'rel', 'cssClass', 'htmlId',
            'htmlAttributes', 'ariaLabel', 'titleAttribute', 'icon', 'badge', 'description',
            'image', 'featured', 'fallbackBehavior', 'fallbackUrl', 'visibility', 'metadata',
            'contentId', 'dateCreated', 'dateUpdated', 'uid',
        ];

        return array_combine($columns, array_map(fn(string $c) => [$c], $columns));
    }

    /**
     * The service refuses a duplicate handle before writing, but a race
     * between two saves can only be lost by the database — so uniqueness has
     * to be the database's rule as well.
     */
    public function testTheGroupHandleIsUniqueInTheDatabase(): void
    {
        $this->insertGroup('main');

        $this->expectException(\yii\db\IntegrityException::class);
        $this->insertGroup('main');
    }

    /**
     * The tree read orders by `(groupId, parentId, sortOrder)` and the health
     * check looks items up by `elementId`; both are index scans or table
     * scans depending only on this.
     *
     * `craft\db\mysql\Schema::findIndexes()` is not usable here — it reads
     * the *application's* connection rather than the one it is called on, so
     * it would report the shared fixture's tables. `SHOW INDEX` against this
     * connection is the honest question.
     *
     * @dataProvider indexProvider
     */
    public function testTheIndexesTheReadPathsDependOnExist(string $table, array $columns): void
    {
        $this->assertContains($columns, $this->indexedColumnSets($table));
    }

    /** @return array<string,array{string,string[]}> */
    public static function indexProvider(): array
    {
        return [
            'group handle' => [self::GROUPS, ['handle']],
            'item hierarchy' => [self::ITEMS, ['groupId', 'parentId', 'sortOrder']],
            'item handle' => [self::ITEMS, ['groupId', 'handle']],
            'item element' => [self::ITEMS, ['elementId']],
            // Unique: a content element belongs to exactly one item, so two
            // items sharing one would mean two items sharing field values.
            'item content' => [self::ITEMS, ['contentId']],
        ];
    }

    // ---------------------------------------------------------------------
    // Referential integrity — the part a text assertion cannot check
    // ---------------------------------------------------------------------

    public function testDeletingAGroupTakesItsItemsWithIt(): void
    {
        $groupId = $this->insertGroup('main');
        $this->insertItem($groupId, 'Home');
        $this->insertItem($groupId, 'About');

        self::$db->createCommand()->delete(self::GROUPS, ['id' => $groupId])->execute();

        $this->assertSame(0, $this->countItems());
    }

    public function testDeletingAParentItemTakesItsDescendantsWithIt(): void
    {
        $groupId = $this->insertGroup('main');
        $parentId = $this->insertItem($groupId, 'Products');
        $childId = $this->insertItem($groupId, 'Shoes', $parentId);
        $this->insertItem($groupId, 'Running', $childId);

        self::$db->createCommand()->delete(self::ITEMS, ['id' => $parentId])->execute();

        $this->assertSame(0, $this->countItems());
    }

    public function testAnItemCannotBeOrphanedFromItsGroup(): void
    {
        $this->expectException(\yii\db\IntegrityException::class);
        $this->insertItem(999999, 'Nowhere');
    }

    public function testAnItemCannotNameAParentThatDoesNotExist(): void
    {
        $groupId = $this->insertGroup('main');

        $this->expectException(\yii\db\IntegrityException::class);
        $this->insertItem($groupId, 'Orphan', 999999);
    }

    // ---------------------------------------------------------------------
    // Upgrade / re-run
    // ---------------------------------------------------------------------

    /**
     * There is one migration and one schema version, so "upgrade" today means
     * exactly this: `safeUp()` running again over a database that already has
     * the tables — which is what happens when the plugin's schema version is
     * bumped for reasons that need no new columns, and what the `tableExists`
     * guards in the migration exist for. It must neither fail nor touch data.
     */
    public function testRunningTheInstallAgainKeepsTheSchemaAndTheData(): void
    {
        $groupId = $this->insertGroup('main');
        $this->insertItem($groupId, 'Home');

        $before = $this->columnNames(self::ITEMS);

        $this->assertTrue($this->migration()->safeUp());
        self::$db->schema->refresh();

        $this->assertSame($before, $this->columnNames(self::ITEMS));
        $this->assertSame(1, $this->countItems());
        $this->assertSame(1, (int)(new \yii\db\Query())->from(self::GROUPS)->count('*', self::$db));
    }

    public function testAHalfInstalledDatabaseIsCompleted(): void
    {
        // The groups table survives, the items table does not — the state a
        // migration that failed part-way through leaves behind.
        self::$db->createCommand()->dropTable(self::ITEMS)->execute();
        self::$db->schema->refresh();

        $this->assertTrue($this->migration()->safeUp());
        self::$db->schema->refresh();

        $this->assertNotNull(self::$db->schema->getTableSchema(self::ITEMS, true));
    }

    // ---------------------------------------------------------------------
    // Uninstall
    // ---------------------------------------------------------------------

    public function testUninstallDropsBothTables(): void
    {
        $this->assertTrue($this->migration()->safeDown());
        self::$db->schema->refresh();

        $this->assertSame([], self::ownTables());
    }

    public function testUninstallSucceedsEvenWithRowsAndForeignKeysInPlace(): void
    {
        $groupId = $this->insertGroup('main');
        $this->insertItem($groupId, 'Home');

        $this->assertTrue($this->migration()->safeDown());
        self::$db->schema->refresh();

        $this->assertSame([], self::ownTables());
    }

    public function testAReinstallAfterAnUninstallStartsCleanRatherThanFailing(): void
    {
        $groupId = $this->insertGroup('main');
        $this->insertItem($groupId, 'Home');

        $this->assertTrue($this->migration()->safeDown());
        self::$db->schema->refresh();
        $this->assertTrue($this->migration()->safeUp());
        self::$db->schema->refresh();

        $this->assertSame(0, $this->countItems());
        $this->assertNotNull(self::$db->schema->getTableSchema(self::GROUPS, true));
    }

    /**
     * Menu configuration is database-backed only, so an uninstall is the
     * whole story — there is no project-config path left behind for a
     * reinstall to replay.
     */
    public function testUninstallLeavesNoProjectConfigBehind(): void
    {
        $this->assertNull(Craft::$app->getProjectConfig()->get('menuBuilder'));
        $this->assertNull(Craft::$app->getProjectConfig()->get('menu-builder'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * The tables this class owns, so the shared fixture's identically-named
     * ones are never counted, asserted against, or dropped.
     *
     * @return string[]
     */
    private static function ownTables(): array
    {
        $tables = array_values(array_filter(
            self::$db->schema->getTableNames(),
            fn(string $table) => str_starts_with($table, self::PREFIX),
        ));
        sort($tables);

        return $tables;
    }

    /**
     * The column sets covered by an index on the given table, in index order.
     *
     * @return array<int,string[]>
     */
    private function indexedColumnSets(string $table): array
    {
        $raw = self::$db->schema->getRawTableName($table);
        $sets = [];

        foreach (self::$db->createCommand('SHOW INDEX FROM ' . self::$db->quoteTableName($raw))->queryAll() as $row) {
            $sets[$row['Key_name']][(int)$row['Seq_in_index']] = $row['Column_name'];
        }

        return array_values(array_map(static function(array $columns) {
            ksort($columns);

            return array_values($columns);
        }, $sets));
    }

    private function insertGroup(string $handle): int
    {
        self::$db->createCommand()->insert(self::GROUPS, [
            'name' => ucfirst($handle),
            'handle' => $handle,
            'enabled' => true,
            'sortOrder' => 0,
            'htmlAttributes' => '{}',
            'settings' => '{}',
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        return (int)self::$db->getLastInsertID();
    }

    private function insertItem(int $groupId, string $title, ?int $parentId = null): int
    {
        self::$db->createCommand()->insert(self::ITEMS, [
            'groupId' => $groupId,
            'parentId' => $parentId,
            'type' => MenuBuilderItem::TYPE_URL,
            'title' => $title,
            'enabled' => true,
            'sortOrder' => 0,
            'clickable' => true,
            'customUrl' => '/' . strtolower($title),
            'target' => '_self',
            'htmlAttributes' => '{}',
            'visibility' => '[]',
            'metadata' => '{}',
            'fallbackBehavior' => 'hide',
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        return (int)self::$db->getLastInsertID();
    }

    private function countItems(): int
    {
        return (int)(new \yii\db\Query())->from(self::ITEMS)->count('*', self::$db);
    }

    /** @return string[] */
    private function columnNames(string $table): array
    {
        $names = array_keys(self::$db->schema->getTableSchema($table, true)->columns);
        sort($names);

        return $names;
    }
}

<?php

/**
 * Bootstrap for the **integration** suite (`tests/Integration`), which runs
 * against a real, booted Craft 5 application and a real database.
 *
 * This is deliberately separate from `tests/bootstrap.php`, which boots
 * nothing: the unit suite covers pure logic and must stay fast and
 * dependency-free. What it cannot cover is precisely what this suite exists
 * for — whether a field value actually round-trips through Craft's content
 * storage, whether `Entry::find()->navigation($uid)` compiles to a query that
 * matches it, and what Craft's GraphQL layer does with the field's type.
 *
 * ## What it boots, and why it is safe
 *
 * A throwaway Craft install of its own:
 *
 * - Its own **database** (`MENUBUILDER_TEST_DB_DATABASE`, default
 *   `menubuilder_test`) — never the development site's. The suite drops and
 *   recreates every table in it, so pointing this at a real database would
 *   destroy it; {@see assertSafeDatabaseName()} refuses to run against one
 *   whose name doesn't say it is a test database.
 * - Its own **config/storage/templates** under `tests/_craft`, so the
 *   development site's `config/project/` is never read and never applied.
 * - The plugin's **own** `vendor/` — which registers MenuBuilder as a real
 *   Craft plugin (`vendor/craftcms/plugins.php`) — so nothing about the
 *   surrounding project is involved.
 *
 * ## Connection
 *
 * Defaults match DDEV as seen from inside the web container (`db:3306`),
 * falling back to the host-published port. Override with
 * `MENUBUILDER_TEST_DB_*` environment variables.
 */

use craft\db\Connection;
use craft\migrations\Install;
use craft\models\Site;
use Tahadudhiya\MenuBuilder\MenuBuilder;

define('CRAFT_BASE_PATH', __DIR__ . '/_craft');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');

require CRAFT_VENDOR_PATH . '/autoload.php';

/**
 * A database this suite is allowed to wipe. The suite's very first act is to
 * drop every table it finds, so an accidental `MENUBUILDER_TEST_DB_DATABASE=db`
 * would silently destroy the development site. Requiring the name to announce
 * itself is a cheap, unmissable guard — the same fail-closed instinct the
 * plugin's visibility layer applies to malformed config.
 */
$assertSafeDatabaseName = static function(string $database): void {
    if (!str_contains(strtolower($database), 'test')) {
        fwrite(STDERR, sprintf(
            "Refusing to run integration tests against database \"%s\": the name must contain \"test\".\n" .
            "This suite drops every table in the database it is pointed at.\n",
            $database
        ));
        exit(1);
    }
};

$env = static fn(string $name, string $default): string => getenv($name) !== false ? (string)getenv($name) : $default;

$database = $env('MENUBUILDER_TEST_DB_DATABASE', 'menubuilder_test');
$assertSafeDatabaseName($database);

$dbConfig = [
    'dsn' => sprintf(
        'mysql:host=%s;port=%s;dbname=%s',
        $env('MENUBUILDER_TEST_DB_SERVER', 'db'),
        $env('MENUBUILDER_TEST_DB_PORT', '3306'),
        $database,
    ),
    'user' => $env('MENUBUILDER_TEST_DB_USER', 'db'),
    'password' => $env('MENUBUILDER_TEST_DB_PASSWORD', 'db'),
    'tablePrefix' => '',
];

foreach ([
    'CRAFT_ENVIRONMENT' => 'test',
    'CRAFT_APP_ID' => 'menu-builder-integration',
    'CRAFT_SECURITY_KEY' => 'menu-builder-integration-tests',
    'CRAFT_ALLOW_ADMIN_CHANGES' => '1',
    'CRAFT_DB_DRIVER' => 'mysql',
    'CRAFT_DB_SERVER' => $env('MENUBUILDER_TEST_DB_SERVER', 'db'),
    'CRAFT_DB_PORT' => $env('MENUBUILDER_TEST_DB_PORT', '3306'),
    'CRAFT_DB_DATABASE' => $database,
    'CRAFT_DB_USER' => $env('MENUBUILDER_TEST_DB_USER', 'db'),
    'CRAFT_DB_PASSWORD' => $env('MENUBUILDER_TEST_DB_PASSWORD', 'db'),
    'CRAFT_DB_TABLE_PREFIX' => '',
] as $name => $value) {
    putenv("$name=$value");
    $_SERVER[$name] = $value;
}

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

/** @var Connection $db */
$db = $app->getDb();

// A clean install per run. Integration tests that build sections, fields and
// entries are only trustworthy if they start from a known schema; reusing a
// half-migrated database from a previous run is how an integration suite
// starts reporting yesterday's answers.
// Foreign keys are switched off for the sweep rather than the tables sorted
// into dependency order: Craft's schema is a cycle, so there is no order that
// works, and the database is empty a moment later either way.
$db->createCommand()->checkIntegrity(false)->execute();

foreach ($db->schema->getTableNames() as $table) {
    $db->createCommand()->dropTable($table)->execute();
}

$db->createCommand()->checkIntegrity(true)->execute();
$db->schema->refresh();

$migration = new Install([
    'db' => $db,
    'username' => 'integration',
    'password' => 'integration-tests-2024!',
    'email' => 'integration@example.test',
    'site' => new Site([
        'name' => 'MenuBuilder integration',
        'handle' => 'default',
        'hasUrls' => true,
        'baseUrl' => 'https://primary.test/',
        'language' => 'en-US',
        'primary' => true,
    ]),
]);

// Craft's migrations narrate themselves to stdout, which would bury the test
// results. Kept only if something fails, where it is the only diagnostic.
ob_start();
$installed = $migration->up(true);
$migrationOutput = (string)ob_get_clean();

if (!$installed) {
    fwrite(STDERR, $migrationOutput . "\nCraft install migration failed.\n");
    exit(1);
}

$app->setIsInstalled(true);

// Installed as **Pro**, because the shared fixture this suite is built on
// (see CraftIntegrationTestCase) needs five menus, and the Free edition
// allows one. Free is not left untested by that: MenuBuilderMenuLimitTest
// switches the running plugin's edition per test — that is the whole
// mechanism Craft uses, so switching it is exactly what a license change
// does — and puts it back afterwards.
ob_start();
$pluginInstalled = $app->getPlugins()->installPlugin('menu-builder', MenuBuilder::EDITION_PRO);
$pluginOutput = (string)ob_get_clean();

if (!$pluginInstalled) {
    fwrite(STDERR, $pluginOutput . "\nMenuBuilder plugin install failed.\n");
    exit(1);
}

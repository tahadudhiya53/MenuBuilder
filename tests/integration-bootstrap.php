<?php

/**
 * Bootstrap for the **integration** suite (`tests/Integration`), which runs against a real, booted
 * Craft 5 application and a real database.
*/

use craft\db\Connection;
use craft\migrations\Install;
use craft\models\Site;
use Tahadudhiya\MenuBuilder\MenuBuilder;

define('CRAFT_BASE_PATH', __DIR__ . '/_craft');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');

require CRAFT_VENDOR_PATH . '/autoload.php';

/**
 * A database this suite is allowed to wipe.
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

// A clean install per run.
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

// Craft's migrations narrate themselves to stdout, which would bury the test results.
ob_start();
$installed = $migration->up(true);
$migrationOutput = (string)ob_get_clean();

if (!$installed) {
    fwrite(STDERR, $migrationOutput . "\nCraft install migration failed.\n");
    exit(1);
}

$app->setIsInstalled(true);

// Exercise a fresh native install of either edition, then use Pro for the shared
// multi-menu fixture. An empty value tests Craft's default edition selection.
$installEdition = $env('MENUBUILDER_TEST_INSTALL_EDITION', MenuBuilder::EDITION_PRO);
ob_start();
$pluginInstalled = $app->getPlugins()->installPlugin('menu-builder', $installEdition ?: null);
$pluginOutput = (string)ob_get_clean();

if (!$pluginInstalled) {
    fwrite(STDERR, $pluginOutput . "\nMenuBuilder plugin install failed.\n");
    exit(1);
}

$expectedEdition = $installEdition ?: MenuBuilder::EDITION_FREE;

if (!MenuBuilder::getInstance()->is($expectedEdition) ||
    $app->getProjectConfig()->get('plugins.menu-builder.edition') !== $expectedEdition) {
    throw new RuntimeException('Craft did not install the requested MenuBuilder edition.');
}

$app->getPlugins()->switchEdition('menu-builder', MenuBuilder::EDITION_PRO);

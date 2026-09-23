<?php

/**
 * Test bootstrap.
*/

$autoloadCandidates = [
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;

        // The consuming project's autoloader is preferred above because it is the one that has
        // Craft in it — but where MenuBuilder is *installed* there rather than symlinked, both
        // its PSR-4 map and its optimized classmap point at the released copy under that
        // project's vendor directory, not at the checkout these tests belong to. The suite would
        // then pass or fail on code nobody here had edited. Registering ahead of Composer's own
        // loader — a classmap entry would otherwise win — pins the plugin's namespace to this
        // source tree.
        spl_autoload_register(static function(string $class): void {
            $prefix = 'Tahadudhiya\\MenuBuilder\\';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $file = __DIR__ . '/../src/'
                . str_replace('\\', '/', substr($class, strlen($prefix)))
                . '.php';

            if (file_exists($file)) {
                require $file;
            }
        }, prepend: true);

        $yiiClass = dirname($autoload) . '/yiisoft/yii2/Yii.php';

        if (file_exists($yiiClass)) {
            require $yiiClass;
        }

        // Craft::t() falls back to plain strtr() placeholder substitution when Craft::$app is null
        // (see yii\BaseYii::t()), so loading just the class — no booted app — is enough for
        // model/rule validation messages to work in these no-booted-app unit tests.
        $craftClass = dirname($autoload) . '/craftcms/cms/src/Craft.php';

        if (file_exists($craftClass)) {
            require $craftClass;
        }

        return;
    }
}

fwrite(STDERR, "Could not find a Composer autoloader. Run `composer install` in the consuming Craft project first.\n");
exit(1);

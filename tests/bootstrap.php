<?php

/**
 * Test bootstrap. Uses the consuming Craft install's autoloader (this
 * plugin has no vendor/ of its own — it's installed via a Composer path
 * repository) so these tests only cover logic that doesn't require a
 * booted Craft\Yii application (no DB, no Craft::$app).
 */

$autoloadCandidates = [
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;

        $yiiClass = dirname($autoload) . '/yiisoft/yii2/Yii.php';

        if (file_exists($yiiClass)) {
            require $yiiClass;
        }

        return;
    }
}

fwrite(STDERR, "Could not find a Composer autoloader. Run `composer install` in the consuming Craft project first.\n");
exit(1);

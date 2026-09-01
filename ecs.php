<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->parallel();
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    // The integration harness boots a real Craft install under tests/_craft,
    // which fills storage/ with Craft's own generated code (compiled Twig
    // templates, CustomFieldBehavior). None of it is ours to style.
    $ecsConfig->skip([
        __DIR__ . '/tests/_craft/storage/*',
    ]);

    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};

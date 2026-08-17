<?php

namespace Tahadudhiya\MenuBuilder\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;
use craft\web\assets\garnish\GarnishAsset;

class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;
        $this->depends = [CraftCpAsset::class, GarnishAsset::class];
        $this->css = ['menu-builder-cp.css'];
        $this->js = [
            'js/menu-builder.js',
            'js/item-fields.js',
            'js/slideout.js',
            'js/tree.js',
        ];

        parent::init();
    }
}

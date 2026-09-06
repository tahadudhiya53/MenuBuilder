<?php

namespace Tahadudhiya\MenuBuilder\web\assets\nav;

use craft\web\AssetBundle;

/**
 * The optional front-end *enhancement* for the bundled `_macros/tree.twig` renderer: Escape, the
 * arrow keys, Home/End, and closing one mega-menu panel when another opens.
*/
class NavAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;
        $this->js = ['js/menu-builder-nav.js'];

        parent::init();
    }
}

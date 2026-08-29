<?php

namespace Tahadudhiya\MenuBuilder\web\assets\nav;

use craft\web\AssetBundle;

/**
 * The optional front-end *enhancement* for the bundled `_macros/tree.twig`
 * renderer: Escape, the arrow keys, Home/End, and closing one mega-menu panel
 * when another opens.
 *
 * Deliberately an enhancement and nothing more. The mega menu's open/closed
 * state is a native `<details open>` — the browser renders it, a pointer or
 * Enter/Space toggles it, and a screen reader reads its state from the same
 * attribute — so the markup is correct and operable with this bundle absent.
 * Nothing here has to be registered for an accessibility guarantee to hold;
 * registering it only adds keys.
 *
 * Optional, and deliberately not registered for you: a site that writes its
 * own navigation markup, or already owns its own disclosure behaviour,
 * should not have this pushed into every page. Register it from the template
 * that renders the menu:
 *
 *     {% do view.registerAssetBundle('Tahadudhiya\\MenuBuilder\\web\\assets\\nav\\NavAsset') %}
 *
 * It depends on nothing — no jQuery, no Garnish, no Craft CP assets — because
 * it runs on the front end of somebody else's site.
 *
 * There is no CSS here on purpose, and the script owns no state of its own:
 * `details[open]` is both the state and the styling hook, so a theme keys its
 * panel styling off `details[open] > .menu-builder-megamenu-panel` and there
 * is no second attribute that could disagree with what is on screen.
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

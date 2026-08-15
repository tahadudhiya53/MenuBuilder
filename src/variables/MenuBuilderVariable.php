<?php

namespace Tahadudhiya\MenuBuilder\variables;

use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;
use Tahadudhiya\MenuBuilder\MenuBuilder;

/**
 * Exposed in Twig as `craft.menuBuilder`.
 *
 * {% set menu = craft.menuBuilder.get('main') %}
 * {% for item in menu %}...{% endfor %}
 */
class MenuBuilderVariable
{
    public function get(string $groupHandle, ?string $currentUri = null): ?MenuBuilderTree
    {
        return MenuBuilder::getInstance()->resolver->getTree($groupHandle, $currentUri);
    }
}

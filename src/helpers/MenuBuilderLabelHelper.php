<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use Craft;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * What a menu item is called on screen.
 *
 * There used to be three copies of this rule with three different answers: the tree row said
 * "(uses linked element's title)" for a blank element-backed title, the quick-add parent picker and
 * the editor heading both said "(untitled)", and none of them showed the element's actual name — so
 * a menu of entry links read as a column of identical placeholders. This is the one rule all of
 * them now ask.
*/
class MenuBuilderLabelHelper
{
    /**
     * The label for `$item`, preferring what the editor typed and falling through to the linked
     * element's own title.
     *
     * The order is the same one MenuBuilderLinkResolver renders with — see
     * {@see LinkAttributeHelper::resolveTitle()}, which is reused here rather than restated — so
     * the name beside a row is the name that will appear in the menu. `$elementTitle` is supplied
     * by the caller because it comes from a batched, per-site lookup
     * ({@see \Tahadudhiya\MenuBuilder\services\MenuBuilderLinkHealthService::getElementTitles()});
     * resolving it here would mean one query per row.
     *
     * A title of "0" is a real title, hence the explicit comparison rather than an emptiness test.
     *
     * @param string|null $elementTitle the linked element's title on the current site, if any
    */
    public static function itemLabel(MenuBuilderItem $item, ?string $elementTitle = null): string
    {
        $label = trim(LinkAttributeHelper::resolveTitle(
            trim((string)$item->title),
            $elementTitle,
        ));

        if ($label !== '') {
            return $label;
        }

        // Nothing to show. For an element-backed item that means the link is broken or the element
        // has no title on this site — the row's health badge says which — so the placeholder still
        // has to explain *why* it is blank rather than just calling it untitled.
        return in_array($item->type, MenuBuilderItem::ELEMENT_TYPES, true)
            ? Craft::t('menubuilder', "(uses linked element's title)")
            : Craft::t('menubuilder', '(untitled)');
    }

    /**
     * The same label for every item in a list, keyed by item ID.
     *
     * @param MenuBuilderItem[] $items
     * @param array<int,string> $elementTitles item ID => linked element's title
     * @return array<int,string>
    */
    public static function itemLabels(array $items, array $elementTitles = []): array
    {
        $labels = [];

        foreach ($items as $item) {
            if ($item->id === null) {
                continue;
            }

            $labels[$item->id] = self::itemLabel($item, $elementTitles[$item->id] ?? null);
        }

        return $labels;
    }
}

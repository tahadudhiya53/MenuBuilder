<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderLinkHealth;

/**
 * Answers "will this menu item's link work, and if not, why" for the control panel.
*/
class MenuBuilderLinkHealthService extends Component
{
    /**
     * Health for every item in a menu, keyed by item ID — healthy ones included, so a caller can
     * tell "checked and fine" from "not checked".
     *
     * @return array<int,MenuBuilderLinkHealth>
    */
    public function getForGroup(int $groupId): array
    {
        return $this->getForItems(MenuBuilder::getInstance()->items->getFlatForGroup($groupId));
    }

    /**
     * Linked elements' titles on the current site, collected as a side-benefit of the batched
     * lookup {@see elementStatuses()} already performs, keyed `[type][elementId]`. Null means the
     * element was found but has no usable title.
     *
     * @var array<string,array<int,string|null>>
    */
    private array $elementTitles = [];

    /**
     * Classifications already resolved this request, keyed `[type][elementId]`, so the health pass
     * and the label pass over the same rows share one batch.
     *
     * @var array<string,array<int,string>>
    */
    private array $elementStatusCache = [];

    /**
     * The linked element's own title for each of `$items` that has one, keyed by *item* ID.
     *
     * This is what the control panel shows on a row whose own `title` is blank — the same value
     * MenuBuilderLinkResolver would fall through to when rendering, resolved for the current site,
     * so the CP names a row the way the front end will. Items with no linked element, or whose
     * element is missing or untitled, are simply absent from the result.
     *
     * @param MenuBuilderItem[] $items
     * @return array<int,string>
    */
    public function getElementTitles(array $items): array
    {
        // Populates $elementTitles as it goes; the batch is shared with the health pass the CP
        // already runs for these same rows.
        $this->elementStatuses($items);

        $titles = [];

        foreach ($items as $item) {
            if ($item->id === null || $item->elementId === null) {
                continue;
            }

            $title = $this->elementTitles[$item->type][(int)$item->elementId] ?? null;

            if ($title !== null) {
                $titles[$item->id] = $title;
            }
        }

        return $titles;
    }

    /**
     * @param MenuBuilderItem[] $items
     * @return array<int,MenuBuilderLinkHealth>
    */
    public function getForItems(array $items): array
    {
        $statuses = $this->elementStatuses($items);
        $health = [];

        foreach ($items as $item) {
            if ($item->id === null) {
                continue;
            }

            $health[$item->id] = $this->build($item, $this->statusFor($item, $statuses));
        }

        return $health;
    }

    /**
     * One item's health — the slide-out / edit screen's entry point.
    */
    public function getForItem(MenuBuilderItem $item): MenuBuilderLinkHealth
    {
        return $this->build($item, $this->statusFor($item, $this->elementStatuses([$item])));
    }

    /**
     * Item IDs whose linked element no longer exists — the narrow question this service's
     * predecessor (MenuBuilderItemService::getOrphanedItemIds()) answered, now derived from the
     * same single classification everything else uses rather than from a second copy of the lookup.
     *
     * @return array<int,true>
    */
    public function getMissingElementItemIds(int $groupId): array
    {
        $missing = [];

        foreach ($this->getForGroup($groupId) as $itemId => $health) {
            if ($health->status === MenuBuilderLinkHealth::STATUS_MISSING) {
                $missing[$itemId] = true;
            }
        }

        return $missing;
    }

    /**
     * Wraps a classified status in the item's own fallback configuration, so the warning can say
     * what the front end is doing about it right now.
    */
    private function build(MenuBuilderItem $item, string $status): MenuBuilderLinkHealth
    {
        return new MenuBuilderLinkHealth(
            status: $status,
            fallbackBehavior: $item->fallbackBehavior,
            itemEnabled: $item->enabled,
            fallbackUsable: MenuBuilderLinkHealth::isFallbackUsable($item),
        );
    }

    /**
     * @param array<string,array<int,string>> $statuses Per-type element status map, see {@see elementStatuses()}.
    */
    private function statusFor(MenuBuilderItem $item, array $statuses): string
    {
        $nonElement = MenuBuilderLinkHealth::forNonElementItem($item);

        if ($nonElement !== null) {
            // A dynamic item's *config* was checked above; whether the section / category group /
            // volume it names still exists needs the app, so it is checked here.
            if ($item->type === MenuBuilderItem::TYPE_DYNAMIC && $nonElement === MenuBuilderLinkHealth::STATUS_HEALTHY) {
                return $this->dynamicSourceExists($item)
                    ? MenuBuilderLinkHealth::STATUS_HEALTHY
                    : MenuBuilderLinkHealth::STATUS_INVALID_SOURCE;
            }

            return $nonElement;
        }

        // Element-backed.
        if ($item->elementId === null) {
            return MenuBuilderLinkHealth::STATUS_MISSING;
        }

        return $statuses[$item->type][$item->elementId] ?? MenuBuilderLinkHealth::STATUS_MISSING;
    }

    /**
     * Resolves every element-backed item in one pass, grouped by type.
     *
     * @param MenuBuilderItem[] $items
     * @return array<string,array<int,string>> item type => element ID => health status
    */
    private function elementStatuses(array $items): array
    {
        $idsByType = [];

        foreach ($items as $item) {
            if ($item->elementId !== null && isset(MenuBuilderLinkHealth::elementClasses()[$item->type])) {
                $idsByType[$item->type][(int)$item->elementId] = true;
            }
        }

        $result = [];
        $site = Craft::$app->getSites()->getCurrentSite();

        foreach ($idsByType as $type => $idSet) {
            $elementClass = MenuBuilderLinkHealth::elementClasses()[$type];
            $this->elementStatusCache[$type] ??= [];
            $this->elementTitles[$type] ??= [];

            // Only ask about elements this request hasn't already looked up. The dashboard needs
            // both the health of these rows and the titles behind them, and each is a separate
            // entry point; without this the same two element queries per type ran twice per page.
            $ids = array_values(array_diff(
                array_keys($idSet),
                array_keys($this->elementStatusCache[$type]),
            ));

            if (empty($ids)) {
                foreach (array_keys($idSet) as $id) {
                    $result[$type][$id] = $this->elementStatusCache[$type][$id];
                }

                continue;
            }

            $existsAnywhere = array_flip(array_map('intval', $elementClass::find()
                ->id($ids)
                ->site('*')
                ->unique()
                ->status(null)
                ->select(['elements.id'])
                ->column()));

            /** @var ElementInterface[] $onThisSite */
            $onThisSite = $elementClass::find()
                ->id($ids)
                ->site($site)
                ->status(null)
                ->all();

            $statuses = [];

            foreach ($onThisSite as $element) {
                $statuses[(int)$element->id] = MenuBuilderLinkHealth::forElementStatus(
                    $element::class,
                    $element->getStatus(),
                    $this->hasUrl($element),
                );

                // These elements are already loaded, and loaded for the *current* site, which is
                // exactly the title the CP should show beside the row. Keeping it here costs
                // nothing — the alternative is a second pass over the same rows — and `status(null)`
                // above means a disabled or unpublished element still gets named, which is
                // precisely when an editor most needs to know which row they are looking at.
                $title = trim((string)($element->title ?? ''));
                $this->elementTitles[$type][(int)$element->id] = $title !== '' ? $title : null;
            }

            foreach ($ids as $id) {
                $this->elementStatusCache[$type][$id] = match (true) {
                    isset($statuses[$id]) => $statuses[$id],
                    isset($existsAnywhere[$id]) => MenuBuilderLinkHealth::STATUS_NOT_ON_SITE,
                    default => MenuBuilderLinkHealth::STATUS_MISSING,
                };
            }

            foreach (array_keys($idSet) as $id) {
                $result[$type][$id] = $this->elementStatusCache[$type][$id];
            }
        }

        return $result;
    }

    /**
     * `getUrl()` is not a plain accessor — an asset in a misconfigured volume throws from it.
    */
    private function hasUrl(ElementInterface $element): bool
    {
        try {
            return $element->getUrl() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether a dynamic item's source container still exists.
    */
    private function dynamicSourceExists(MenuBuilderItem $item): bool
    {
        $stored = $item->metadata['dynamicSource'] ?? null;
        $config = MenuBuilderDynamicNavigationService::normalizeConfig(is_array($stored) ? $stored : []);

        if ($config === null) {
            return false;
        }

        return match ($config['sourceType']) {
            'entries' => Craft::$app->getEntries()->getSectionById($config['sourceId']) !== null,
            'categories' => Craft::$app->getCategories()->getGroupById($config['sourceId']) !== null,
            'assets' => Craft::$app->getVolumes()->getVolumeById($config['sourceId']) !== null,
        };
    }
}

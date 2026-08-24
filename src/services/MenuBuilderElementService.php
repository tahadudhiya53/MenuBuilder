<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\services\Elements;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\records\MenuBuilderItemRecord;
use yii\base\Event;

/**
 * Keeps cached navigation trees in sync with the Craft elements they link to.
 * `ElementLinkResolver` already re-queries elements fresh on every cache
 * rebuild (see its docblock) — the only thing missing was telling the cache
 * *when* to rebuild. This service closes that gap: it listens for the
 * element lifecycle events that can change what a linked element resolves to
 * (save — including title/URI/status changes — delete, and restore) and
 * invalidates only the navigation groups that actually reference the
 * affected element, via a single indexed query against `menubuilder_items`.
 */
class MenuBuilderElementService extends Component
{
    private const WATCHED_CLASSES = [Entry::class, Category::class, Asset::class];

    private bool $listenersAttached = false;

    public function attachListeners(): void
    {
        if ($this->listenersAttached) {
            return;
        }

        $this->listenersAttached = true;

        $handler = function(ElementEvent $event) {
            $this->handleElementChange($event->element);
        };

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $handler);
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $handler);
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, $handler);
    }

    /**
     * Invalidates every navigation group with an item referencing the given
     * element, unless the element is a draft/revision/provisional draft —
     * those are never what a menu item's elementId points at (see spec §14,
     * "Draft / Revision Safety") and reacting to them would invalidate
     * caches for edits nobody has published yet.
     */
    public function handleElementChange(ElementInterface $element): void
    {
        if (!$this->isWatchedElement($element)) {
            return;
        }

        $groupIds = $this->getAffectedGroupIds((int)$element->id);

        // A brand-new element can't be matched by elementId above, but it
        // could still be picked up by an enabled `dynamic` item's source
        // query (Phase 8) — invalidate those groups too. Empty (i.e. no
        // behavior change) when the install has no dynamic items.
        $groupIds = array_unique(array_merge(
            $groupIds,
            MenuBuilder::getInstance()->items->getGroupIdsWithDynamicItems()
        ));

        if (empty($groupIds)) {
            return;
        }

        $handles = [];
        foreach ($groupIds as $groupId) {
            $group = MenuBuilder::getInstance()->groups->getById($groupId);

            if ($group !== null) {
                $handles[] = $group->handle;
            }
        }

        MenuBuilder::getInstance()->cache->invalidateGroups($handles);
    }

    private function isWatchedElement(ElementInterface $element): bool
    {
        if ($element->getIsDraft() || $element->getIsRevision()) {
            return false;
        }

        foreach (self::WATCHED_CLASSES as $class) {
            if ($element instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * A single indexed lookup (menubuilder_items.elementId is indexed, see
     * the Install migration) — never a per-item scan, regardless of how many
     * navigation items or elements exist (spec §25, Performance).
     *
     * @return int[] Distinct group IDs.
     */
    private function getAffectedGroupIds(int $elementId): array
    {
        return array_map(
            'intval',
            MenuBuilderItemRecord::find()
                ->select(['groupId'])
                ->distinct()
                ->where(['elementId' => $elementId])
                ->column()
        );
    }
}

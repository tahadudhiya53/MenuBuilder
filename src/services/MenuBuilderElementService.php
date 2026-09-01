<?php

namespace Tahadudhiya\MenuBuilder\services;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\CategoryGroupEvent;
use craft\events\ElementEvent;
use craft\events\SectionEvent;
use craft\events\VolumeEvent;
use craft\services\Categories;
use craft\services\Elements;
use craft\services\Entries;
use craft\services\Sites;
use craft\services\Volumes;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\records\MenuBuilderItemRecord;
use yii\base\Event;

/**
 * Keeps cached navigation trees in sync with the Craft elements they link to.
 * `ElementLinkResolver` already re-queries elements fresh on every cache
 * rebuild (see its docblock), so nothing about a linked element's URL or
 * title is ever *stored* on a menu item — the only thing this service has to
 * get right is telling the cache *when* to rebuild.
 *
 * It listens for two families of change:
 *
 * 1. **Element lifecycle** — save (title/slug/URI/status/publish/unpublish/
 *    disable), delete (soft or hard), restore, and slug/URI updates that
 *    happen *without* a save (a structure move re-writes an entry's and its
 *    descendants' URIs through `Elements::updateElementSlugAndUri()`, which
 *    fires only `EVENT_AFTER_UPDATE_SLUG_AND_URI`).
 * 2. **Container config** — a section / category group / volume save can
 *    change URI formats or an asset base URL, i.e. the URL of every element
 *    inside it. Craft only resaves entries for this when `autoResaveEntries`
 *    is on, and never resaves assets for a volume change, so the element
 *    events above can't be relied on.
 * 3. **Site config** — a site save or delete is the same kind of change one
 *    level further out: it can move every URL on that site to a different
 *    base URL or language, disable the site, or (on delete) transfer its
 *    content to another site. See {@see handleSiteChange()}.
 *
 * The first two paths invalidate only the navigation groups that actually
 * reference the affected element(s) — via indexed lookups on
 * `menubuilder_items` — plus the groups whose enabled `dynamic` items point
 * at the *same* section/group/volume, by menu ID. Never a blanket flush. The
 * third is the one genuinely global case, and the only caller of
 * MenuBuilderCacheService::invalidateAll() anywhere in the plugin
 * (MenuBuilderGroupTest pins that).
 */
class MenuBuilderElementService extends Component
{
    private const WATCHED_CLASSES = [Entry::class, Category::class, Asset::class];

    /** Dynamic-source type => [container table, container column, item type]. */
    private const CONTAINERS = [
        'entries' => [CraftTable::ENTRIES, 'sectionId', MenuBuilderItem::TYPE_ENTRY],
        'categories' => [CraftTable::CATEGORIES, 'groupId', MenuBuilderItem::TYPE_CATEGORY],
        'assets' => [CraftTable::ASSETS, 'volumeId', MenuBuilderItem::TYPE_ASSET],
    ];

    private bool $listenersAttached = false;

    public function attachListeners(): void
    {
        if ($this->listenersAttached) {
            return;
        }

        $this->listenersAttached = true;

        $elementHandler = function(ElementEvent $event) {
            $this->handleElementChange($event->element);
        };

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $elementHandler);
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $elementHandler);
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, $elementHandler);
        // A structure move (and the queued job it pushes) changes URIs
        // through this path only — no save event is fired for it.
        Event::on(Elements::class, Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI, $elementHandler);

        Event::on(
            Entries::class,
            Entries::EVENT_AFTER_SAVE_SECTION,
            function(SectionEvent $event) {
                $this->handleContainerChange('entries', (int)$event->section->id);
            }
        );

        Event::on(
            Categories::class,
            Categories::EVENT_AFTER_SAVE_GROUP,
            function(CategoryGroupEvent $event) {
                $this->handleContainerChange('categories', (int)$event->categoryGroup->id);
            }
        );

        Event::on(
            Volumes::class,
            Volumes::EVENT_AFTER_SAVE_VOLUME,
            function(VolumeEvent $event) {
                $this->handleContainerChange('assets', (int)$event->volume->id);
            }
        );

        $siteHandler = function() {
            $this->handleSiteChange();
        };

        Event::on(Sites::class, Sites::EVENT_AFTER_SAVE_SITE, $siteHandler);
        Event::on(Sites::class, Sites::EVENT_AFTER_DELETE_SITE, $siteHandler);
    }

    /**
     * A site save or delete invalidates *every* cached tree, on every site —
     * the one case where the blanket flush this service otherwise avoids is
     * the correct scope.
     *
     * Cached trees are keyed by (site, menu, config version) and hold
     * resolved element URLs and titles, both of which come from the site
     * being rendered. A site save can change the base URL every one of those URLs
     * was built from, change the language the titles were read in, or
     * disable the site outright; a site delete can transfer that site's
     * content to another site, changing URLs on a site that was never itself
     * edited. None of that fires an element event — Craft resaves nothing
     * for it — so without this a navigation menu would keep serving the old
     * site's URLs until something unrelated happened to invalidate it.
     *
     * This is also the path a deployment takes: sites live in project
     * config, and `project-config/apply` reaches them through
     * `Sites::handleChangedSite()`/`handleDeletedSite()`, which fire exactly
     * these two events. Navigation groups and items are database-only (see
     * MenuBuilderGroupService), so a deploy never rewrites them — but it can
     * rewrite the sites their cached trees were resolved against.
     */
    public function handleSiteChange(): void
    {
        MenuBuilder::getInstance()->cache->invalidateAll();
    }

    /**
     * Invalidates every navigation group with an item referencing the given
     * element, unless the element is a draft/revision/provisional draft —
     * those are never what a menu item's elementId points at, and reacting
     * to them would invalidate caches for edits nobody has published yet.
     */
    public function handleElementChange(ElementInterface $element): void
    {
        if (!$this->isWatchedElement($element)) {
            return;
        }

        $sourceType = self::sourceTypeForElement($element::class);

        $groupIds = array_merge(
            $this->getAffectedGroupIds((int)$element->id),
            // A brand-new element can't be matched by elementId above, but
            // it could still be picked up by an enabled `dynamic` item's
            // source query — so the dynamic items whose source *is* this
            // element's section/group/volume are invalidated too. Scoped by
            // container rather than "every group with any dynamic item":
            // saving a blog entry must not flush a menu whose only dynamic
            // item lists assets from one volume.
            $sourceType !== null
                ? $this->getGroupIdsWithMatchingDynamicItems($sourceType, $this->containerIdOf($element))
                : []
        );

        $this->invalidateGroupIds($groupIds);
    }

    /**
     * A section / category-group / volume save can change the resolved URL
     * of every element inside it without touching any single element, so
     * this invalidates the groups referencing *any* element in that
     * container, plus the dynamic items sourced from it.
     */
    public function handleContainerChange(string $sourceType, int $containerId): void
    {
        if (!isset(self::CONTAINERS[$sourceType]) || $containerId < 1) {
            return;
        }

        $this->invalidateGroupIds(array_merge(
            $this->getGroupIdsReferencingContainer($sourceType, $containerId),
            $this->getGroupIdsWithMatchingDynamicItems($sourceType, $containerId)
        ));
    }

    /**
     * Which dynamic-source type a watched element class belongs to, or null
     * for anything MenuBuilder doesn't link to. Pure + static so the mapping
     * is unit-testable without a booted Craft app (Craft elements can't be
     * instantiated without one), same reasoning as
     * {@see \Tahadudhiya\MenuBuilder\linktypes\ElementLinkResolver::isPubliclyAvailable()}.
     *
     * @param class-string $elementClass
     * @return 'entries'|'categories'|'assets'|null
     */
    public static function sourceTypeForElement(string $elementClass): ?string
    {
        return match (true) {
            is_a($elementClass, Entry::class, true) => 'entries',
            is_a($elementClass, Category::class, true) => 'categories',
            is_a($elementClass, Asset::class, true) => 'assets',
            default => null,
        };
    }

    /**
     * Whether a stored `dynamicSource` config could contain an element of
     * the given source type and container.
     *
     * Normalization is delegated to
     * {@see MenuBuilderDynamicNavigationService::normalizeConfig()} — the
     * same clamping/whitelisting the render-time query uses — so a config
     * that would resolve to nothing at render time can never justify an
     * invalidation here.
     *
     * A null `$containerId` means the element's container couldn't be
     * determined (a nested entry has no `sectionId`); that fails *open* and
     * matches every dynamic source of the right type rather than risking a
     * stale menu.
     *
     * @param array<string,mixed> $config
     */
    public static function dynamicSourceMatches(array $config, string $sourceType, ?int $containerId): bool
    {
        $normalized = MenuBuilderDynamicNavigationService::normalizeConfig($config);

        if ($normalized === null || $normalized['sourceType'] !== $sourceType) {
            return false;
        }

        return $containerId === null || $normalized['sourceId'] === $containerId;
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
     * The section / category group / volume the element lives in, or null
     * when it has none (a nested entry's `sectionId` is null) — see
     * {@see dynamicSourceMatches()} for how null is treated.
     */
    private function containerIdOf(ElementInterface $element): ?int
    {
        $id = match (true) {
            $element instanceof Entry => $element->sectionId,
            $element instanceof Category => $element->groupId,
            $element instanceof Asset => $element->getVolumeId(),
            default => null,
        };

        return $id !== null && $id > 0 ? (int)$id : null;
    }

    /**
     * A single indexed lookup (menubuilder_items.elementId is indexed, see
     * the Install migration) — never a per-item scan, regardless of how many
     * navigation items or elements exist.
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

    /**
     * Groups with an item linking to *any* element in the given container.
     * Expressed as a sub-query on the element's own table so the potentially
     * large ID list never round-trips through PHP.
     *
     * @return int[] Distinct group IDs.
     */
    private function getGroupIdsReferencingContainer(string $sourceType, int $containerId): array
    {
        [$table, $column, $itemType] = self::CONTAINERS[$sourceType];

        $elementIds = (new Query())
            ->select(['id'])
            ->from([$table])
            ->where([$column => $containerId]);

        return array_map(
            'intval',
            MenuBuilderItemRecord::find()
                ->select(['groupId'])
                ->distinct()
                ->where(['type' => $itemType])
                ->andWhere(['elementId' => $elementIds])
                ->column()
        );
    }

    /**
     * @return int[] Distinct group IDs.
     */
    private function getGroupIdsWithMatchingDynamicItems(string $sourceType, ?int $containerId): array
    {
        $itemsService = MenuBuilder::getInstance()->items;

        // Fail open when the element's container is unknown: reuse the
        // coarser "any group with a dynamic item" list rather than reading
        // and normalizing every dynamic config just to match all of them.
        if ($containerId === null) {
            return $itemsService->getGroupIdsWithDynamicItems();
        }

        $groupIds = [];

        foreach ($itemsService->getDynamicSourceConfigsByGroup() as $groupId => $configs) {
            foreach ($configs as $config) {
                if (self::dynamicSourceMatches($config, $sourceType, $containerId)) {
                    $groupIds[] = $groupId;

                    break;
                }
            }
        }

        return $groupIds;
    }

    /**
     * Invalidation is keyed by menu **ID** (MenuBuilderCacheService::groupTag()),
     * which is exactly what the lookups above already return — so an
     * entry/category/asset save costs no group lookup at all, however many
     * menus reference the element, and a menu handle rename can never leave
     * an entry uninvalidated. Deduplication and the empty case are the cache
     * service's ({@see MenuBuilderCacheService::invalidateGroupIds()}).
     *
     * @param int[] $groupIds
     */
    private function invalidateGroupIds(array $groupIds): void
    {
        MenuBuilder::getInstance()->cache->invalidateGroupIds($groupIds);
    }
}

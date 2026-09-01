<?php

namespace Tahadudhiya\MenuBuilder\linktypes;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\ResolvedLink;

/**
 * Shared by entry/category/asset link types. Always re-queries the element
 * fresh, scoped to the current site, so a deleted/disabled/moved element or
 * a changed URI is reflected immediately — there is no stored URL to go
 * stale. Falls back per the item's configured fallbackBehavior when the
 * element is unavailable.
 *
 * Soft-deleted (trashed) elements, drafts and revisions are excluded by the
 * query's own defaults, so a soft delete falls back and a restore starts
 * resolving again with no extra handling here.
 */
class ElementLinkResolver implements PreloadingLinkTypeResolverInterface
{
    /**
     * How many IDs go into one preload query. A menu with more linked
     * elements than this costs a query per chunk instead of one per *item* —
     * still a constant-factor read, without an unbounded `IN (...)` list.
     */
    private const PRELOAD_CHUNK_SIZE = 500;

    /**
     * Elements gathered by {@see preload()}, as `[siteId][elementId] =>
     * element|null`.
     *
     * Keyed by site because the query below is site-scoped and the same
     * element legitimately resolves to a different URL, title and
     * availability per site — a request that resolves a menu for more than
     * one site (the preview, a console command) must not be served another
     * site's answer. A `null` value is a *recorded absence*: an ID the query
     * didn't return, remembered so it isn't re-queried per item.
     *
     * @var array<int,array<int,ElementInterface|null>>
     */
    private array $preloaded = [];

    public function __construct(private readonly string $elementClass)
    {
    }

    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        if ($item->elementId === null) {
            return self::fallbackFor($item);
        }

        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;

        // array_key_exists(), not `??`: a preloaded *absence* is stored as
        // null and must short-circuit exactly like a hit, or an unavailable
        // element would be re-queried once per item — the N+1 this exists to
        // remove, in its worst case.
        if (array_key_exists($item->elementId, $this->preloaded[$siteId] ?? [])) {
            $element = $this->preloaded[$siteId][$item->elementId];
        } else {
            /** @var ElementInterface|null $element */
            $element = ($this->elementClass)::find()
                ->id($item->elementId)
                ->site(Craft::$app->getSites()->getCurrentSite())
                ->status(null)
                ->one();
        }

        if ($element === null || !self::isPubliclyAvailable($element::class, $element->getStatus())) {
            return self::fallbackFor($item);
        }

        $url = $element->getUrl();

        if ($url === null) {
            return self::fallbackFor($item);
        }

        $label = (string)($element->title ?? '');

        return ResolvedLink::to($url, $label !== '' ? $label : null);
    }

    /**
     * One query per {@see PRELOAD_CHUNK_SIZE} IDs instead of one per item.
     *
     * The query is deliberately identical to the per-item one in
     * {@see resolve()} — same site scoping, same `status(null)` — so a
     * preloaded element is the same element `resolve()` would have fetched
     * for itself. Anything it does *not* return (deleted, soft-deleted, not
     * enabled for this site) is recorded as a null so the fallback path is
     * reached without a second look.
     *
     * @param int[] $elementIds
     */
    public function preload(array $elementIds): void
    {
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $known = $this->preloaded[$siteId] ?? [];

        $wanted = array_values(array_filter(
            array_unique(array_map('intval', $elementIds)),
            fn(int $elementId) => $elementId > 0 && !array_key_exists($elementId, $known)
        ));

        if ($wanted === []) {
            return;
        }

        // Every wanted ID is seeded as an absence *first*, so an ID the
        // query doesn't come back with stays recorded rather than falling
        // through to a per-item query.
        foreach ($wanted as $elementId) {
            $this->preloaded[$siteId][$elementId] = null;
        }

        foreach (array_chunk($wanted, self::PRELOAD_CHUNK_SIZE) as $chunk) {
            /** @var ElementInterface[] $elements */
            $elements = ($this->elementClass)::find()
                ->id($chunk)
                ->site(Craft::$app->getSites()->getCurrentSite())
                ->status(null)
                ->limit(null)
                ->all();

            foreach ($elements as $element) {
                $this->preloaded[$siteId][(int)$element->id] = $element;
            }
        }
    }

    public function releasePreloaded(): void
    {
        $this->preloaded = [];
    }

    /**
     * Availability is decided from the element's *status*, not its `enabled`
     * flag: the query above is scoped to the current site, and
     * `Element::getStatus()` is the only one of the two that accounts for
     * per-site enabled state (`enabledForSite`) — an element enabled
     * globally but disabled for the site being rendered must not produce a
     * link there.
     *
     * Split out as a pure class+status mapping so it's unit-testable without
     * a booted Craft app (Craft elements can't be instantiated without one).
     *
     * @param class-string $elementClass
     */
    public static function isPubliclyAvailable(string $elementClass, ?string $status): bool
    {
        return match (true) {
            is_a($elementClass, Entry::class, true) => $status === Entry::STATUS_LIVE,
            is_a($elementClass, Category::class, true) => $status === Category::STATUS_ENABLED,
            is_a($elementClass, Asset::class, true) => $status === Asset::STATUS_ENABLED,
            default => true,
        };
    }

    /**
     * Public + static for the same testability reason as
     * {@see isPubliclyAvailable()} — this is the whole fallback decision for
     * an unavailable element, with no element or app access of its own.
     */
    public static function fallbackFor(MenuBuilderItem $item): ResolvedLink
    {
        return match ($item->fallbackBehavior) {
            // Re-checked rather than trusted for the same reason as
            // UrlLinkResolver's customUrl: a fallback URL becomes the
            // rendered href, and a stored value isn't guaranteed to have
            // passed validateFallbackUrl().
            MenuBuilderItem::FALLBACK_FALLBACK_URL => $item->fallbackUrl !== null && MenuBuilderItem::isPermissiveUrl($item->fallbackUrl)
                ? ResolvedLink::to($item->fallbackUrl)
                : ResolvedLink::unavailable(),
            MenuBuilderItem::FALLBACK_DISABLE_LINK => ResolvedLink::none(),
            default => ResolvedLink::unavailable(),
        };
    }
}

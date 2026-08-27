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
class ElementLinkResolver implements LinkTypeResolverInterface
{
    public function __construct(private readonly string $elementClass)
    {
    }

    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        if ($item->elementId === null) {
            return self::fallbackFor($item);
        }

        /** @var ElementInterface|null $element */
        $element = ($this->elementClass)::find()
            ->id($item->elementId)
            ->site(Craft::$app->getSites()->getCurrentSite())
            ->status(null)
            ->one();

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

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
 */
class ElementLinkResolver implements LinkTypeResolverInterface
{
    public function __construct(private readonly string $elementClass)
    {
    }

    public function resolve(MenuBuilderItem $item): ResolvedLink
    {
        if ($item->elementId === null) {
            return $this->fallback($item);
        }

        /** @var ElementInterface|null $element */
        $element = ($this->elementClass)::find()
            ->id($item->elementId)
            ->site(Craft::$app->getSites()->getCurrentSite())
            ->status(null)
            ->one();

        if ($element === null || !$this->isPubliclyAvailable($element)) {
            return $this->fallback($item);
        }

        $url = $element->getUrl();

        if ($url === null) {
            return $this->fallback($item);
        }

        $label = (string)($element->title ?? '');

        return ResolvedLink::to($url, $label !== '' ? $label : null);
    }

    private function isPubliclyAvailable(ElementInterface $element): bool
    {
        return match (true) {
            $element instanceof Entry => $element->getStatus() === Entry::STATUS_LIVE,
            $element instanceof Category => $element->enabled,
            $element instanceof Asset => true,
            default => true,
        };
    }

    private function fallback(MenuBuilderItem $item): ResolvedLink
    {
        return match ($item->fallbackBehavior) {
            MenuBuilderItem::FALLBACK_FALLBACK_URL => $item->fallbackUrl
                ? ResolvedLink::to($item->fallbackUrl)
                : ResolvedLink::unavailable(),
            MenuBuilderItem::FALLBACK_DISABLE_LINK => ResolvedLink::none(),
            default => ResolvedLink::unavailable(),
        };
    }
}

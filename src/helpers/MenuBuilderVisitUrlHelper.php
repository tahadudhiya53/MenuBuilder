<?php

namespace Tahadudhiya\MenuBuilder\helpers;

use Craft;
use craft\helpers\UrlHelper;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * Where a menu row's "visit" globe points — the same destination the front end would render for
 * that item, made absolute so the CP can open it in a new tab.
 *
 * Craft's element indexes put a globe beside every row that has a public URL; a menu of links with
 * no way to reach any of them is the one screen in the CP where that is most obviously missing.
 * This resolves the destination through MenuBuilderLinkResolver rather than re-deriving it, so the
 * globe can never send an editor somewhere the menu itself wouldn't.
*/
class MenuBuilderVisitUrlHelper
{
    /**
     * Link types that address a page. An anchor is a fragment of whatever page embeds the menu and
     * a heading/separator/dynamic container has no destination at all, so none of them get a globe
     * — a link to "#contact" on its own goes nowhere.
    */
    private const VISITABLE_TYPES = [
        MenuBuilderItem::TYPE_ENTRY,
        MenuBuilderItem::TYPE_CATEGORY,
        MenuBuilderItem::TYPE_ASSET,
        MenuBuilderItem::TYPE_URL,
    ];

    /**
     * Schemes a browser navigates to a page with. `mailto:` and `tel:` are valid menu links and
     * deliberately excluded: a globe that opens a mail client isn't a preview of anything.
    */
    private const VISITABLE_SCHEMES = ['http', 'https'];

    /**
     * The absolute front-end URL for every item that has one, keyed by item ID.
     *
     * Items are resolved in one pass with the link resolver's preloading, so an all-entry menu
     * costs the same shared element query the tree already pays for rather than one per row.
     *
     * @param MenuBuilderItem[] $items flat list, as returned by MenuBuilderItemService::getFlatForGroup()
     * @return array<int,string>
    */
    public static function forItems(array $items): array
    {
        $resolver = MenuBuilder::getInstance()->linkResolver;
        $resolver->preload($items);

        $urls = [];

        foreach ($items as $item) {
            if ($item->id === null || !in_array($item->type, self::VISITABLE_TYPES, true)) {
                continue;
            }

            $resolved = $resolver->resolve($item);

            if (!$resolved->isAvailable || $resolved->url === null) {
                continue;
            }

            $url = self::absolute($resolved->url);

            if ($url !== null) {
                $urls[$item->id] = $url;
            }
        }

        return $urls;
    }

    /**
     * A resolved destination as something a new tab can open, or null when it isn't a page.
     *
     * A root-relative path is a front-end path, so it becomes a site URL; a full URL is taken as
     * it stands, provided it navigates. Anything else — a fragment, a mailto:, a scheme we don't
     * recognise — has no page to visit.
    */
    public static function absolute(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }

        // A protocol-relative URL ("//example.com/x") is another host's page, reached over
        // whatever scheme the CP is on.
        if (UrlHelper::isProtocolRelativeUrl($url)) {
            return (Craft::$app->getRequest()->getIsSecureConnection() ? 'https:' : 'http:') . $url;
        }

        // `isAbsoluteUrl()`, not `isFullUrl()`: the latter counts a root-relative path as full,
        // and a path has no scheme to check — it would fail this test and lose its globe.
        if (UrlHelper::isAbsoluteUrl($url)) {
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

            // parse_url() gives up on some valid-enough URLs; the scheme is still the prefix.
            if ($scheme === '') {
                $scheme = strtolower(substr($url, 0, (int)strpos($url, ':')));
            }

            return in_array($scheme, self::VISITABLE_SCHEMES, true) ? $url : null;
        }

        // Everything left is a path or query string on this site.
        return UrlHelper::siteUrl($url);
    }
}

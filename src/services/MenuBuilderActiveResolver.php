<?php

namespace Tahadudhiya\MenuBuilder\services;

use craft\base\Component;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;

/**
 * Marks isActive/isActiveAncestor on an already-built node tree by comparing
 * each node's resolved URL against the current request — never a naive
 * full-string comparison, so trailing slashes/query strings/fragments don't
 * cause false negatives.
 *
 * Active state is per-request by definition (it depends on the URI being
 * served) and is therefore never part of what MenuBuilderCacheService stores:
 * this runs as pipeline step 5, on the visibility-filtered *copies* that
 * MenuBuilderNode::withChildren() produced with their flags cleared, so the
 * cached tree is never written to. See ARCHITECTURE.md "Caching".
 */
class MenuBuilderActiveResolver extends Component
{
    /**
     * Only a URL that navigates within a site can be the page currently being
     * served. `mailto:`/`tel:` (and any custom scheme a third-party link type
     * registers) have a `path` as far as parse_url is concerned — `a@b.com`
     * for `mailto:a@b.com` — so without this they could path-match a request.
     */
    private const NAVIGABLE_SCHEMES = ['http', 'https'];

    /**
     * @param MenuBuilderNode[] $topLevelNodes
     * @param string[] $internalHosts Hosts that count as the site being served — the request
     *                               host plus the current site's base-URL host (see
     *                               MenuBuilderResolver::internalHosts(), which explains why a
     *                               sibling site's host is deliberately not one of them). When
     *                               empty, host comparison is skipped entirely, which is what a
     *                               console request or a caller that can't know the host gets.
     */
    public function mark(array $topLevelNodes, string $currentUri, array $internalHosts = []): void
    {
        $currentPath = $this->normalizePath($currentUri);
        $hosts = $this->normalizeHosts([...$internalHosts, $this->host($currentUri)]);

        foreach ($topLevelNodes as $node) {
            $this->markNode($node, $currentPath, $hosts);
        }
    }

    /**
     * @param string[] $hosts
     * @return bool Whether this node or any descendant is active.
     */
    private function markNode(MenuBuilderNode $node, string $currentPath, array $hosts): bool
    {
        $node->isActive = $this->isCurrentPage($node, $currentPath, $hosts);

        $anyChildActive = false;
        foreach ($node->children as $child) {
            if ($this->markNode($child, $currentPath, $hosts)) {
                $anyChildActive = true;
            }
        }

        // An ancestor is marked with `isActiveAncestor`, never `isActive` —
        // that distinction is the whole reason the template can put
        // aria-current="page" on exactly one link (the page being served)
        // while still styling the open branch. It propagates up the whole
        // chain via this return value, so a grandparent of the active node is
        // an active ancestor too.
        $node->isActiveAncestor = $anyChildActive;

        return $node->isActive || $anyChildActive;
    }

    /**
     * @param string[] $hosts
     */
    private function isCurrentPage(MenuBuilderNode $node, string $currentPath, array $hosts): bool
    {
        // An unavailable link (a deleted/disabled element whose item is set to
        // "disable link", a rejected custom URL) is not a destination, so it
        // can't be the current page — checked explicitly rather than relying
        // on those paths also happening to produce a null URL.
        if (!$node->isLinkAvailable || $node->url === null || trim($node->url) === '') {
            return false;
        }

        $url = trim($node->url);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (is_string($scheme) && !in_array(strtolower($scheme), self::NAVIGABLE_SCHEMES, true)) {
            return false;
        }

        // A custom URL pointing at another host ("https://shop.example.com/products/shoes",
        // or a protocol-relative "//example.org/products/shoes") shares nothing with the
        // request but its path, and must not be marked active because the local path
        // happens to be the same.
        $host = $this->host($url);

        if ($host !== null && $hosts !== [] && !in_array($host, $hosts, true)) {
            return false;
        }

        return $this->normalizePath($url) === $currentPath;
    }

    /**
     * Both sides of the comparison are reduced to a leading-slash,
     * no-trailing-slash path, with the query string and fragment dropped. The
     * leading slash matters: an item's URL carries one (either because the
     * editor typed a root-relative path, or because an element URL was
     * absolute and parse_url kept it), while Craft's own `getFullUri()` does
     * not — so without normalizing it, "/news" never matched the request for
     * "news" and nothing was ever marked active.
     */
    private function normalizePath(string $url): string
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? '') : $url;

        if ($path === '') {
            // An absolute URL with no path at all ("https://example.test") is
            // the homepage. A fragment- or query-only URL ("#top", "?page=2")
            // is not a page of its own, so it keeps its raw form here and can
            // never collide with the homepage's "/".
            $path = is_array($parts) && isset($parts['host']) ? '/' : $url;
        }

        return rtrim('/' . ltrim($path, '/'), '/') ?: '/';
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * @param array<int,string|null> $hosts
     * @return string[]
     */
    private function normalizeHosts(array $hosts): array
    {
        $normalized = [];

        foreach ($hosts as $host) {
            if (!is_string($host)) {
                continue;
            }

            $host = strtolower(trim($this->host($host) ?? $host));

            if ($host !== '') {
                $normalized[$host] = true;
            }
        }

        return array_keys($normalized);
    }
}

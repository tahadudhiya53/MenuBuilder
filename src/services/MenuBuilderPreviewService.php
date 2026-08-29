<?php

namespace Tahadudhiya\MenuBuilder\services;

use Craft;
use craft\base\Component;
use craft\models\Site;
use DateTime;
use DateTimeZone;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderPreviewOptions;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

/**
 * Renders a saved menu the way a chosen audience, on a chosen site, viewing
 * a chosen page would receive it — without changing anything.
 *
 * **This service performs no writes.** It has no `save`, no `delete`, no
 * transaction; it substitutes a simulated {@see VisibilityContext} and a
 * simulated current site for the duration of one resolve and restores the
 * site afterwards. Nothing about a preview is persisted, and nothing about
 * it can reach another request: the audience is applied *after* the shared
 * cache (MenuBuilderResolver::getTree()), which is the same boundary that
 * keeps one visitor's visibility answer out of another's cache entry.
 *
 * What a preview therefore *is*: the **saved** menu, resolved through the
 * production pipeline — same link resolution, same cache entry, same
 * visibility rules, same active-state matching, same MenuBuilderNode. There
 * is no draft/unsaved state to preview: every control-panel mutation in this
 * plugin (drag, reorder, toggle, item save) writes immediately, so "saved"
 * and "what the editor is looking at" are the same thing, and the preview
 * says so rather than implying a draft it cannot have. See ARCHITECTURE.md
 * "Preview".
 */
class MenuBuilderPreviewService extends Component
{
    /**
     * The previewed tree, or null when the menu doesn't exist, is disabled,
     * or isn't available on the simulated site — all three of which are
     * front-end outcomes the editor needs to be able to see, so they're
     * reported as "renders nothing", not as an error.
     */
    public function getTree(string $groupHandle, MenuBuilderPreviewOptions $options): ?MenuBuilderTree
    {
        $context = $this->buildContext($options);

        return $this->withSite(
            $options->siteId,
            fn(): ?MenuBuilderTree => MenuBuilder::getInstance()->resolver->getTree($groupHandle, $options->uri, $context)
        );
    }

    /**
     * The simulated audience. Only the visitor-shaped facts are simulated:
     * `now`, the timezone and the environment come from the live
     * application, so a `dateRange` or `environment` rule answers in the
     * preview exactly what it answers for a real visitor at this moment.
     */
    public function buildContext(MenuBuilderPreviewOptions $options): VisibilityContext
    {
        $timezone = new DateTimeZone(Craft::$app->getTimeZone());

        return $options->toVisibilityContext(new DateTime('now', $timezone), $timezone, Craft::$app->env);
    }

    /**
     * The sites this user may preview, as `MenuBuilderPreviewOptions::normalize()`
     * expects them.
     *
     * Craft's own editable-sites boundary is reused rather than a second
     * rule of this plugin's own: previewing another site resolves that
     * site's entries, categories and assets, so it must not become a way to
     * read content from a site the user has no access to. A user with no
     * site permissions at all still gets their current site, so the screen
     * always renders something.
     *
     * @return int[]
     */
    public function allowedSiteIds(): array
    {
        $editable = Craft::$app->getSites()->getEditableSiteIds();

        if ($editable !== []) {
            return array_values(array_map('intval', $editable));
        }

        return [(int)Craft::$app->getSites()->getCurrentSite()->id];
    }

    /**
     * @return array<array{label: string, value: string}>
     */
    public function siteOptions(): array
    {
        $allowed = $this->allowedSiteIds();
        $options = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (in_array((int)$site->id, $allowed, true)) {
                $options[] = ['label' => $site->name, 'value' => (string)$site->id];
            }
        }

        return $options;
    }

    /**
     * The user groups an audience may be simulated as.
     *
     * Every group is offered, deliberately: simulating one reveals which
     * *menu items* are restricted to it, and that restriction is already on
     * screen in the item editor for anyone who can reach this page. It
     * grants no access to the group, its users, or anything a
     * group-restricted item links to — element links resolve through the
     * same publicly-visible-status boundary the front end uses, whoever is
     * previewing (see ElementLinkResolver).
     *
     * @return int[]
     */
    public function allowedUserGroupIds(): array
    {
        return array_map(
            static fn($group): int => (int)$group->id,
            Craft::$app->getUserGroups()->getAllGroups()
        );
    }

    /**
     * @return array<array{label: string, value: string}>
     */
    public function userGroupOptions(): array
    {
        return array_map(
            static fn($group): array => ['label' => $group->name, 'value' => (string)$group->id],
            Craft::$app->getUserGroups()->getAllGroups()
        );
    }

    /**
     * Runs `$callback` with the given site as Craft's current site, then puts
     * the real one back.
     *
     * The switch is what makes "preview on another site" mean anything:
     * ElementLinkResolver queries elements against the current site, so an
     * entry link resolves to that site's URL, title and status — the same
     * per-site answer a visitor to that site gets. It is request-scoped
     * (Craft's current site is not persisted), and the `finally` is
     * load-bearing: an exception mid-resolve must not leave the rest of the
     * control-panel request rendering as a different site.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withSite(?int $siteId, callable $callback): mixed
    {
        $sites = Craft::$app->getSites();
        $original = $sites->getCurrentSite();

        if ($siteId === null || $siteId === (int)$original->id) {
            return $callback();
        }

        $site = $sites->getSiteById($siteId);

        if (!$site instanceof Site) {
            return $callback();
        }

        $sites->setCurrentSite($site);

        try {
            return $callback();
        } finally {
            $sites->setCurrentSite($original);
        }
    }

    /**
     * What the preview's (decorative) browser bar shows: the simulated
     * site's host followed by the previewed path.
     *
     * Pure and static so the one piece of string-building the stage needs is
     * testable, and host-only by design — a site's base URL can carry a
     * path, credentials or a port that nobody needs to read off a mock
     * address bar, and the previewed path is the half that actually
     * changes. Falls back to the path alone for a site with no base URL
     * (one that can't produce an absolute URL in the first place).
     */
    public static function addressLabel(?string $baseUrl, string $uri): string
    {
        $host = $baseUrl !== null ? parse_url($baseUrl, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host . $uri : $uri;
    }

    /** Elements that close themselves, so they must not open an indent level. */
    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** One indent step in the "Rendered markup" panel. */
    private const INDENT = '    ';

    /**
     * Re-indents rendered navigation markup for the "Rendered markup" panel:
     * one element per line, nested by depth, no blank runs.
     *
     * Twig emits readable *templates*, not readable *output* — the macro's
     * conditional attributes each sit on their own source line, so the raw
     * output arrives with an anchor's attributes scattered down a dozen
     * lines and long gaps where a `{% if %}` didn't match. That is unusable
     * as an inspection tool, which is the panel's whole job.
     *
     * **Display only.** The stage renders the *unformatted* capture, so
     * nothing an editor sees as navigation depends on this method, and a bug
     * here can't change what the front end does. It is also purely
     * textual: it re-spaces, it never adds, removes or reorders an element
     * or an attribute.
     *
     * Safe to run over the markup because the input is *rendered* HTML —
     * editor-authored text has already been escaped by Twig, so a title
     * reading `<script>` is `&lt;script&gt;` here and cannot be mistaken for
     * a tag. The output is still escaped again by the template that prints
     * it; this method never marks anything safe.
     *
     * One deliberate lossy step: whitespace inside a tag is collapsed to
     * single spaces, so a newline an editor typed into a `title` attribute
     * reads as a space in this panel. The stage and the front end are
     * unaffected.
     *
     * Pure and static so the formatter is unit-tested against real macro
     * output rather than trusted.
     */
    public static function formatMarkup(string $markup): string
    {
        $tokens = preg_split('/(<[^>]*>)/', $markup, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!self::isTag($token)) {
                $text = self::collapse($token);

                if ($text !== '') {
                    $lines[] = str_repeat(self::INDENT, $depth) . $text;
                }

                continue;
            }

            $tag = self::collapse($token);

            if (str_starts_with($tag, '</')) {
                $depth = max(0, $depth - 1);
                $lines[] = str_repeat(self::INDENT, $depth) . $tag;

                continue;
            }

            if (self::isSelfContained($tag)) {
                $lines[] = str_repeat(self::INDENT, $depth) . $tag;

                continue;
            }

            // `<span class="badge">New</span>` reads better whole than split
            // across three lines, so an element holding nothing but text is
            // kept on one line. Anything with a child element still nests.
            $closing = '</' . self::tagName($tag) . '>';

            if (isset($tokens[$i + 2])
                && !self::isTag($tokens[$i + 1])
                && self::collapse($tokens[$i + 2]) === $closing
            ) {
                $lines[] = str_repeat(self::INDENT, $depth) . $tag . self::collapse($tokens[$i + 1]) . $closing;
                $i += 2;

                continue;
            }

            $lines[] = str_repeat(self::INDENT, $depth) . $tag;
            $depth++;
        }

        return implode("\n", $lines);
    }

    private static function isTag(string $token): bool
    {
        return str_starts_with($token, '<') && str_ends_with($token, '>');
    }

    private static function collapse(string $value): string
    {
        $collapsed = trim((string)preg_replace('/\s+/', ' ', $value));

        // The macro's conditional attributes leave a space before the closing
        // bracket whenever the last one didn't apply (`<a href="/" >`). It is
        // invisible in a browser and noise in a source panel.
        return (string)preg_replace('/\s+(\/?)>$/', '$1>', $collapsed);
    }

    /** A tag that opens nothing: a void element, a self-closing tag, a comment or a doctype. */
    private static function isSelfContained(string $tag): bool
    {
        return str_starts_with($tag, '<!')
            || str_ends_with($tag, '/>')
            || in_array(self::tagName($tag), self::VOID_ELEMENTS, true);
    }

    private static function tagName(string $tag): string
    {
        preg_match('/^<\/?([a-zA-Z][a-zA-Z0-9-]*)/', $tag, $matches);

        return strtolower($matches[1] ?? '');
    }

    /**
     * How many nodes in a previewed tree came from saved menu items —
     * everything except the children a `dynamic` item synthesises, which
     * are elements rather than rows and so can't be compared against the
     * menu's item count.
     *
     * Pure and static (like DashboardController::countTree()) so the
     * "N of M items are in this preview" summary is testable without a
     * booted app.
     *
     * @param MenuBuilderNode[] $nodes
     */
    public static function countPersistedNodes(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            $count += ($node->isDynamic ? 0 : 1) + self::countPersistedNodes($node->children);
        }

        return $count;
    }

    /**
     * The counterpart to {@see countPersistedNodes()}: nodes synthesised
     * from a dynamic source. Reported separately so a preview showing more
     * links than the menu has items is self-explanatory.
     *
     * @param MenuBuilderNode[] $nodes
     */
    public static function countDynamicNodes(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            $count += ($node->isDynamic ? 1 : 0) + self::countDynamicNodes($node->children);
        }

        return $count;
    }
}

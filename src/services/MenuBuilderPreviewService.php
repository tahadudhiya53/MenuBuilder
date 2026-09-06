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
 * Renders a saved menu for a chosen audience and site without changing anything.
*/
class MenuBuilderPreviewService extends Component
{
    /**
     * The previewed tree, or null when the menu doesn't exist, is disabled, or isn't available on
     * the simulated site — all three of which are front-end outcomes the editor needs to be able
     * to see, so they're reported as "renders nothing", not as an error.
    */
    public function getTree(string $groupHandle, MenuBuilderPreviewOptions $options): ?MenuBuilderTree
    {
        $context = $this->buildContext($options);

        return $this->withSite(
            $options->siteId,
            fn(): ?MenuBuilderTree => MenuBuilder::getInstance()->resolver->getTree(
                $groupHandle,
                context: $context,
                markActive: false,
            )
        );
    }

    /**
     * The simulated audience.
    */
    public function buildContext(MenuBuilderPreviewOptions $options): VisibilityContext
    {
        $timezone = new DateTimeZone(Craft::$app->getTimeZone());

        return $options->toVisibilityContext(new DateTime('now', $timezone), $timezone, Craft::$app->env);
    }

    /**
     * The sites this user may preview, as `MenuBuilderPreviewOptions::normalize()` expects them.
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

    /** @return array<array{label: string, value: string}> */
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
     * @return int[]
    */
    public function allowedUserGroupIds(): array
    {
        return array_map(
            static fn($group): int => (int)$group->id,
            Craft::$app->getUserGroups()->getAllGroups()
        );
    }

    /** @return array<array{label: string, value: string}> */
    public function userGroupOptions(): array
    {
        return array_map(
            static fn($group): array => ['label' => $group->name, 'value' => (string)$group->id],
            Craft::$app->getUserGroups()->getAllGroups()
        );
    }

    /**
     * Runs `$callback` with the given site as Craft's current site, then puts the real one back.
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
     * Elements that close themselves, so they must not open an indent level.
    */
    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /**
     * One indent step in the "Rendered markup" panel.
    */
    private const INDENT = '    ';

    /**
     * Re-indents rendered navigation markup for the "Rendered markup" panel: one element per line,
     * nested by depth, no blank runs.
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

            // `<span class="badge">New</span>` reads better whole than split across three lines, so
            // an element holding nothing but text is kept on one line.
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

        // The macro's conditional attributes leave a space before the closing bracket whenever the
        // last one didn't apply (`<a href="/" >`).
        return (string)preg_replace('/\s+(\/?)>$/', '$1>', $collapsed);
    }

    /**
     * A tag that opens nothing: a void element, a self-closing tag, a comment or a doctype.
    */
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
     * How many nodes in a previewed tree came from saved menu items — everything except the
     * children a `dynamic` item synthesises, which are elements rather than rows and so can't be
     * compared against the menu's item count.
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
     * The counterpart to {@see countPersistedNodes()}: nodes synthesised from a dynamic source.
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

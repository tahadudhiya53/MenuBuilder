<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * Small pure-PHP helpers for building a link's title, rel and HTML
 * attributes. Kept out of MenuBuilderResolver so they are unit testable
 * without booting Craft (see tests/Unit/MenuBuilderHelpersTest.php).
 */
class LinkAttributeHelper
{
    /**
     * An explicit editor title always wins; otherwise fall back to the
     * linked element's own title — never overwritten once set.
     */
    public static function resolveTitle(string $itemTitle, ?string $elementLabel): string
    {
        return $itemTitle !== '' ? $itemTitle : ($elementLabel ?? '');
    }

    /**
     * `target=_blank` must carry `rel="noopener"` for tab-nabbing safety, but
     * an editor's own rel values (nofollow, sponsored, custom) are merged in
     * rather than overwritten. Duplicate tokens (however they got
     * there — repeated editor input, a merge, etc.) are collapsed, order and
     * casing of first occurrence preserved.
     *
     * Dedup is case-insensitive because rel tokens are: an editor-typed
     * `NOOPENER` or a `Nofollow` alongside `nofollow` is the same token to a
     * browser, and emitting both would just be a redundant attribute value.
     */
    public static function mergeRelForTarget(string $target, ?string $rel): ?string
    {
        return self::combineRel($target === '_blank' ? [$rel, 'noopener'] : [$rel]);
    }

    /**
     * Collapses several rel values (a stored attribute, individual tokens, or
     * both) into one attribute value: first occurrence of each token wins,
     * comparison case-insensitive, an empty result is `null` rather than an
     * empty attribute.
     *
     * The editor can set the same token twice without noticing — the
     * `nofollow` checkbox plus a hand-typed `nofollow` in the custom `rel`
     * field — so the dedupe has to happen where the value is *built*
     * (ItemsController::buildRel()) and not only where it's rendered, or the
     * stored value carries the duplicate around forever. Both call this, so
     * there is one definition of what a merged rel looks like.
     *
     * @param array<int,string|null> $values
     */
    public static function combineRel(array $values): ?string
    {
        $tokens = [];

        foreach ($values as $value) {
            if ($value === null || trim($value) === '') {
                continue;
            }

            foreach (preg_split('/\s+/', trim($value)) as $token) {
                $tokens[strtolower($token)] ??= $token;
            }
        }

        return $tokens === [] ? null : implode(' ', array_values($tokens));
    }

    /**
     * Whether an item's resolved link should render as an actual `<a href>`.
     *
     * All three conditions must hold, and `clickable` is an explicit editor
     * choice — never inferred from a URL being present (see
     * MenuBuilderItem's class docblock). A blank URL counts as no URL: an
     * `<a href="">` is a link back to the current page, which is a worse
     * outcome than the label the item was going to be anyway.
     */
    public static function isClickable(bool $isLinkable, bool $clickable, ?string $url): bool
    {
        return $isLinkable && $clickable && $url !== null && trim($url) !== '';
    }

    /**
     * An HTML `id` is a single token: whitespace would split it into two
     * attributes' worth of value, and quote/angle characters are the shapes
     * that matter if a custom template ever interpolates it somewhere Twig
     * isn't escaping. Same denylist as
     * {@see \Tahadudhiya\MenuBuilder\models\MenuBuilderItem::isValidAnchorTarget()},
     * since an anchor link's target is an element id on the front end.
     */
    public static function isValidHtmlId(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && preg_match('/^[^\s"\'<>]+$/', $value) === 1;
    }

    /**
     * A class attribute is a whitespace-separated token list, so whitespace
     * is legal here where it isn't in an id — but the same quote/angle
     * characters are not.
     */
    public static function isValidCssClassList(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && preg_match('/^[^"\'<>]+$/', $value) === 1;
    }

    /**
     * Parses the edit forms' `key: value`-per-line textarea into an
     * attributes bag. Shared by both controllers, and kept next to the
     * validator that inspects the bag it produces.
     *
     * @return array<string,string>
     */
    public static function parseAttributeLines(string $input): array
    {
        $attributes = [];

        foreach (explode("\n", $input) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));

            if ($key !== '') {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * Schemes that execute rather than navigate, matched anywhere in an
     * attribute value because the bag's keys are open-ended: any of them
     * could be the one a custom template renders into an `href`/`src`.
     * `data:` is deliberately absent — unlike the two here it has ordinary
     * legitimate uses (an inline image on a `data-*` attribute), and a
     * resolved link's own `data:` URL is already refused by
     * MenuBuilderItem::isPermissiveUrl().
     */
    private const DENIED_ATTRIBUTE_VALUE_SCHEMES = ['javascript', 'vbscript'];

    /**
     * Validates an HTML-attributes bag for injection-shaped keys/values —
     * event-handler-shaped attribute names (`onclick`, `onload`, `onerror`,
     * anything else starting `on`) and executing-scheme values
     * ({@see DENIED_ATTRIBUTE_VALUE_SCHEMES}) — as defense-in-depth beyond Twig's own
     * output escaping. Shared by MenuBuilderItem and MenuBuilderGroup, whose
     * `htmlAttributes` bags are both eventually rendered onto markup by
     * downstream Twig templates.
     *
     * @param array<mixed,mixed> $attributes
     * @return string[] Human-readable error messages; empty when safe.
     */
    public static function validateHtmlAttributes(array $attributes): array
    {
        $errors = [];

        foreach ($attributes as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_:-]*$/', $key)) {
                $errors[] = "\"$key\" is not a valid attribute name.";

                continue;
            }

            if (stripos($key, 'on') === 0) {
                $errors[] = "Event handler attributes like \"$key\" are not allowed.";

                continue;
            }

            // Whitespace and control characters are stripped before the
            // comparison for the same reason MenuBuilderItem::hasDeniedScheme()
            // strips them: browsers ignore them inside a scheme, so
            // "java\tscript:" is the same URL to a browser and a different
            // string to a naive check.
            $normalizedValue = preg_replace('/[\s\x00-\x1f\x7f]+/', '', (string)$value);

            foreach (self::DENIED_ATTRIBUTE_VALUE_SCHEMES as $scheme) {
                if (stripos($normalizedValue, $scheme . ':') !== false) {
                    $errors[] = "The value for \"$key\" may not use a $scheme: URL.";

                    break;
                }
            }
        }

        return $errors;
    }
}

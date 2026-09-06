<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * Small pure-PHP helpers for building a link's title, rel and HTML attributes.
*/
class LinkAttributeHelper
{
    /**
     * An explicit editor title always wins; otherwise fall back to the linked element's own title
     * — never overwritten once set.
    */
    public static function resolveTitle(string $itemTitle, ?string $elementLabel): string
    {
        return $itemTitle !== '' ? $itemTitle : ($elementLabel ?? '');
    }

    /**
     * `target=_blank` must carry `rel="noopener"` for tab-nabbing safety, but an editor's own rel
     * values (nofollow, sponsored, custom) are merged in rather than overwritten.
    */
    public static function mergeRelForTarget(string $target, ?string $rel): ?string
    {
        return self::combineRel($target === '_blank' ? [$rel, 'noopener'] : [$rel]);
    }

    /**
     * Collapses several rel values (a stored attribute, individual tokens, or both) into one
     * attribute value: first occurrence of each token wins, comparison case-insensitive, an empty
     * result is `null` rather than an empty attribute.
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
    */
    public static function isClickable(bool $isLinkable, bool $clickable, ?string $url): bool
    {
        return $isLinkable && $clickable && $url !== null && trim($url) !== '';
    }

    /**
     * An HTML `id` is a single token: whitespace would split it into two attributes' worth of
     * value, and quote/angle characters are the shapes that matter if a custom template ever
     * interpolates it somewhere Twig isn't escaping.
    */
    public static function isValidHtmlId(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && preg_match('/^[^\s"\'<>]+$/', $value) === 1;
    }

    /**
     * A class attribute is a whitespace-separated token list, so whitespace is legal here where it
     * isn't in an id — but the same quote/angle characters are not.
    */
    public static function isValidCssClassList(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && preg_match('/^[^"\'<>]+$/', $value) === 1;
    }

    /**
     * Parses the edit forms' `key: value`-per-line textarea into an attributes bag.
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
     * Attribute names the bundled macros own, or that decide how assistive technology and the
     * keyboard treat an item.
    */
    public const RESERVED_ATTRIBUTES = [
        'href', 'target', 'rel', 'id', 'class', 'role', 'tabindex',
        'aria-current', 'aria-expanded', 'aria-controls', 'aria-haspopup', 'aria-hidden',
    ];

    /**
     * The render-time half of {@see validateHtmlAttributes()}: the bag an item or a menu actually
     * renders with, with everything unsafe or reserved dropped.
     *
     * @param array<mixed,mixed> $attributes
     * @return array<string,string>
    */
    public static function filterHtmlAttributes(array $attributes): array
    {
        $safe = [];

        foreach ($attributes as $key => $value) {
            if (!is_string($key) || is_array($value) || is_object($value)) {
                continue;
            }

            if (in_array(strtolower(trim($key)), self::RESERVED_ATTRIBUTES, true)) {
                continue;
            }

            if (self::validateHtmlAttributes([$key => $value]) !== []) {
                continue;
            }

            $safe[$key] = (string)$value;
        }

        return $safe;
    }

    /**
     * Schemes that execute rather than navigate, matched anywhere in an attribute value because the
     * bag's keys are open-ended: any of them could be the one a custom template renders into an
     * `href`/`src`.
    */
    private const DENIED_ATTRIBUTE_VALUE_SCHEMES = ['javascript', 'vbscript'];

    /**
     * Validates an HTML-attributes bag for injection-shaped keys/values — event-handler-shaped
     * attribute names (`onclick`, `onload`, `onerror`, anything else starting `on`) and
     * executing-scheme values ({@see DENIED_ATTRIBUTE_VALUE_SCHEMES}) — as defense-in-depth
     * beyond Twig's own output escaping.
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

            // Whitespace and control characters are stripped before the comparison for the same
            // reason MenuBuilderItem::hasDeniedScheme() strips them: browsers ignore them inside a
            // scheme, so "java\tscript:" is the same URL to a browser and a different string to a
            // naive check.
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

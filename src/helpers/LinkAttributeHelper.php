<?php

namespace Tahadudhiya\MenuBuilder\helpers;

/**
 * Small pure-PHP helpers factored out of MenuBuilderResolver so they're unit
 * testable without booting Craft (see tests/Unit/LinkAttributeHelperTest.php).
 */
class LinkAttributeHelper
{
    /**
     * An explicit editor title always wins; otherwise fall back to the
     * linked element's own title (spec §14) — never overwritten once set.
     */
    public static function resolveTitle(string $itemTitle, ?string $elementLabel): string
    {
        return $itemTitle !== '' ? $itemTitle : ($elementLabel ?? '');
    }

    /**
     * `target=_blank` must carry `rel="noopener"` for tab-nabbing safety, but
     * an editor's own rel values (nofollow, sponsored, custom) are merged in
     * rather than overwritten (spec §17). Duplicate tokens (however they got
     * there — repeated editor input, a merge, etc.) are collapsed, order of
     * first occurrence preserved.
     */
    public static function mergeRelForTarget(string $target, ?string $rel): ?string
    {
        $tokens = $rel !== null && trim($rel) !== '' ? preg_split('/\s+/', trim($rel)) : [];
        $tokens = array_values(array_unique($tokens));

        if ($target === '_blank' && !in_array('noopener', $tokens, true)) {
            $tokens[] = 'noopener';
        }

        return $tokens === [] ? null : implode(' ', $tokens);
    }

    /**
     * Validates an HTML-attributes bag for injection-shaped keys/values —
     * event-handler-shaped attribute names (`onclick`, etc.) and
     * `javascript:`-scheme values — as defense-in-depth beyond Twig's own
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

            $normalizedValue = preg_replace('/\s+/', '', (string)$value);

            if (stripos($normalizedValue, 'javascript:') !== false) {
                $errors[] = "The value for \"$key\" may not use a javascript: URL.";
            }
        }

        return $errors;
    }
}

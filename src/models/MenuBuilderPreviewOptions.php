<?php

namespace Tahadudhiya\MenuBuilder\models;

use DateTime;
use DateTimeZone;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

/**
 * What one control-panel preview simulates: a device width, an audience, a
 * site, and the page the menu is being "seen from".
 *
 * This is a *request-scoped* description of a simulation — it is never
 * persisted, never reaches a cache entry, and changes nothing about the
 * menu it previews. It exists so the simulation is one validated object
 * rather than a handful of query params read in a template.
 *
 * Everything here is pure (no Craft app, no request), so
 * {@see normalize()} — the whole security-relevant surface, since it is
 * what turns editor-supplied query params into an audience and a site — is
 * unit-testable, the same reasoning as
 * MenuBuilderResolver::internalHosts() and BaseMenuBuilderController::cpAffordances().
 *
 * Normalization fails **closed**, in the same sense the visibility layer
 * does: an unrecognised value never widens what the preview shows. An
 * unknown audience becomes "logged out" (the narrowest one), a site the
 * previewing user may not see becomes their own current site, and a user
 * group they may not select is dropped rather than honoured.
 */
class MenuBuilderPreviewOptions
{
    public const DEVICE_DESKTOP = 'desktop';
    public const DEVICE_MOBILE = 'mobile';

    public const DEVICES = [self::DEVICE_DESKTOP, self::DEVICE_MOBILE];

    /** Rendered in the page's masthead, the usual home of a primary navigation. */
    public const PLACEMENT_HEADER = 'header';

    /** Rendered in the page's footer — stacked into columns, fully expanded, the way footers are. */
    public const PLACEMENT_FOOTER = 'footer';

    public const PLACEMENTS = [self::PLACEMENT_HEADER, self::PLACEMENT_FOOTER];

    /**
     * Words in a menu's handle or name that make "this is a footer menu" the
     * better *first guess* for the preview. It is only a default — the
     * screen offers both placements, and nothing about a menu records where a
     * template actually renders it, because MenuBuilder deliberately doesn't
     * decide that: `craft.menuBuilder.get()` can be called anywhere.
     */
    private const FOOTER_HINTS = ['footer', 'legal', 'utility', 'bottom'];

    /** An anonymous visitor: `loggedOut` passes, `loggedIn`/`userGroup` items are hidden. */
    public const AUDIENCE_LOGGED_OUT = 'loggedOut';

    /** Any signed-in user belonging to no group in particular. */
    public const AUDIENCE_LOGGED_IN = 'loggedIn';

    /** A signed-in user in the selected user group(s). */
    public const AUDIENCE_USER_GROUP = 'userGroup';

    public const AUDIENCES = [self::AUDIENCE_LOGGED_OUT, self::AUDIENCE_LOGGED_IN, self::AUDIENCE_USER_GROUP];

    /**
     * A generous ceiling on the simulated URI. It only ever feeds
     * active-state matching (never an `href`), but an unbounded string
     * would still be echoed back into the form field.
     */
    public const MAX_URI_LENGTH = 2048;

    public function __construct(
        public readonly string $device = self::DEVICE_DESKTOP,
        /** Where on the mock page the navigation is shown — presentation only, never stored. */
        public readonly string $placement = self::PLACEMENT_HEADER,
        public readonly string $audience = self::AUDIENCE_LOGGED_OUT,
        /** @var int[] Meaningful only when `audience` is `userGroup`. */
        public readonly array $userGroupIds = [],
        public readonly ?int $siteId = null,
        /** The page the menu is rendered "as seen from" — a site-root-relative path. */
        public readonly string $uri = '/',
    ) {
    }

    /**
     * Builds the options for a preview request out of raw query params.
     *
     * `$allowedSiteIds` and `$allowedUserGroupIds` are the boundaries the
     * caller (MenuBuilderPreviewService) is responsible for computing from
     * the *current* user — this method only enforces them. A requested
     * value outside either list is not an error the editor has to read
     * about; it is simply not honoured, and the normalized options are
     * echoed back into the form so the screen always states the audience
     * it actually rendered.
     *
     * @param array<mixed,mixed> $params
     * @param int[] $allowedSiteIds
     * @param int[] $allowedUserGroupIds
     * @param string|null $defaultPlacement Where to show the menu when the request doesn't say —
     *                                      normally {@see guessPlacement()}'s answer for this menu.
     */
    public static function normalize(
        array $params,
        array $allowedSiteIds = [],
        array $allowedUserGroupIds = [],
        ?int $defaultSiteId = null,
        ?string $defaultPlacement = null,
    ): self {
        $audience = self::oneOf($params['audience'] ?? null, self::AUDIENCES, self::AUDIENCE_LOGGED_OUT);
        $groupIds = [];

        if ($audience === self::AUDIENCE_USER_GROUP) {
            $posted = $params['userGroupIds'] ?? null;

            if (is_array($posted)) {
                // Craft's checkboxSelect posts an empty padding value so that
                // an all-unchecked set still arrives as a list. Exactly that
                // value is dropped — nothing else is repaired — because
                // strictIdList() would (correctly) call the whole list
                // malformed over it.
                $posted = array_values(array_filter($posted, static fn(mixed $value): bool => $value !== ''));
            }

            // strictIdList() rather than normalizeIdList(): these IDs decide
            // which group-restricted items the preview reveals, so a `true`
            // or a `"3abc"` must not intval its way into meaning group 1 or
            // group 3 (see ConfigHelper::strictIdList()).
            $requested = ConfigHelper::strictIdList($posted) ?? [];
            $groupIds = array_values(array_intersect($requested, $allowedUserGroupIds));

            // "A user group" with no group the current user may preview is
            // not a narrower audience of its own — it is exactly "some
            // logged-in user". Collapsing it keeps the two from behaving
            // identically while reading differently on screen.
            if ($groupIds === []) {
                $audience = self::AUDIENCE_LOGGED_IN;
            }
        }

        return new self(
            device: self::oneOf($params['device'] ?? null, self::DEVICES, self::DEVICE_DESKTOP),
            placement: self::oneOf(
                $params['placement'] ?? null,
                self::PLACEMENTS,
                self::oneOf($defaultPlacement, self::PLACEMENTS, self::PLACEMENT_HEADER)
            ),
            audience: $audience,
            userGroupIds: $groupIds,
            siteId: self::resolveSiteId($params['siteId'] ?? null, $allowedSiteIds, $defaultSiteId),
            uri: self::normalizeUri($params['uri'] ?? null),
        );
    }

    /**
     * The simulated site, or the caller's default when the requested one
     * isn't one this user may preview. Never an arbitrary posted ID: a site
     * ID picks which site's content the tree resolves against, so it is
     * checked against the allowlist rather than merely cast to an int.
     *
     * @param int[] $allowedSiteIds
     */
    private static function resolveSiteId(mixed $value, array $allowedSiteIds, ?int $defaultSiteId): ?int
    {
        $requested = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int)$value : null);

        if ($requested !== null && in_array($requested, $allowedSiteIds, true)) {
            return $requested;
        }

        if ($defaultSiteId !== null && in_array($defaultSiteId, $allowedSiteIds, true)) {
            return $defaultSiteId;
        }

        return $defaultSiteId ?? ($allowedSiteIds[0] ?? null);
    }

    /**
     * Reduces the "seen from" field to a site-root-relative path, the only
     * shape MenuBuilderActiveResolver compares against.
     *
     * Anything carrying a scheme (`javascript:`, `data:`, `https://…`) or a
     * protocol-relative host (`//elsewhere.test/x`) is discarded rather than
     * repaired — this value is echoed back into a form field and used to
     * decide which item lights up as the current page, and a URL on another
     * host is not a page of this site in the first place (the same rule
     * MenuBuilderResolver::internalHosts() applies at render time). Query
     * strings and fragments are dropped because active matching drops them
     * too, so keeping them would only make the field disagree with the
     * result.
     */
    public static function normalizeUri(mixed $value): string
    {
        if (!is_string($value)) {
            return '/';
        }

        // Deliberately not plain trim(): its default charlist includes the NUL
        // byte, so a `"/news\0"` would be *repaired* into a valid-looking path
        // instead of being rejected by the control-character check below.
        // Surrounding whitespace from a paste is forgiven; a control character
        // in the value is not.
        $uri = trim($value, " \t\n\r\x0B");

        if ($uri === '' || strlen($uri) > self::MAX_URI_LENGTH) {
            return '/';
        }

        // Control characters (including the NUL, tab and newline that are
        // used to smuggle a scheme past a naive check) disqualify the value
        // outright.
        if (preg_match('/[\x00-\x1f\x7f]/', $uri) === 1) {
            return '/';
        }

        // A scheme, a protocol-relative host, or a backslash (which some
        // browsers normalize to `/`, so `\\evil.test` is a host).
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $uri) === 1
            || str_starts_with($uri, '//')
            || str_contains($uri, '\\')
        ) {
            return '/';
        }

        // Explicit truncation rather than strtok(), which skips *leading*
        // delimiters: `?page=2` would come back as `page=2` and be turned
        // into the path `/page=2`, a page nobody asked about.
        foreach (['?', '#'] as $delimiter) {
            $position = strpos($uri, $delimiter);

            if ($position !== false) {
                $uri = substr($uri, 0, $position);
            }
        }

        if ($uri === '') {
            return '/';
        }

        return str_starts_with($uri, '/') ? $uri : '/' . $uri;
    }

    /**
     * The VisibilityContext this simulation stands for.
     *
     * The mapping is the whole point of the preview: it is the *only*
     * difference between a preview render and a front-end render, and it
     * enters the pipeline at exactly the place the real context does —
     * after the shared cache, never inside it (see
     * MenuBuilderResolver::getTree() and ARCHITECTURE.md "Caching").
     *
     * `now`, the timezone and the environment are deliberately **not**
     * simulated: they come from the live application, so a `dateRange` or
     * `environment` rule answers in a preview exactly what it answers for a
     * visitor right now.
     */
    public function toVisibilityContext(DateTime $now, ?DateTimeZone $timezone = null, ?string $environment = null): VisibilityContext
    {
        return new VisibilityContext(
            isLoggedIn: $this->audience !== self::AUDIENCE_LOGGED_OUT,
            userGroupIds: $this->audience === self::AUDIENCE_USER_GROUP ? $this->userGroupIds : [],
            currentSiteId: $this->siteId,
            now: $now,
            environment: $environment,
            timezone: $timezone,
        );
    }

    public function isMobile(): bool
    {
        return $this->device === self::DEVICE_MOBILE;
    }

    public function isFooter(): bool
    {
        return $this->placement === self::PLACEMENT_FOOTER;
    }

    /**
     * The placement to *offer first* for a menu, from its handle and name.
     *
     * Nothing in the data model says where a menu is rendered, and that is
     * deliberate: a template can call `craft.menuBuilder.get('footer')` in a
     * masthead if it likes, so recording a placement would be a second,
     * unenforceable truth (see ARCHITECTURE.md, "Single path per behaviour").
     * This is a *guess for a default*, nothing more — the screen shows both
     * placements and says which one it chose, so a wrong guess costs one
     * click rather than misrepresenting anything.
     *
     * Pure, so the guess is unit-tested rather than trusted.
     */
    public static function guessPlacement(string $handle, string $name = ''): string
    {
        $haystack = strtolower($handle . ' ' . $name);

        foreach (self::FOOTER_HINTS as $hint) {
            if (str_contains($haystack, $hint)) {
                return self::PLACEMENT_FOOTER;
            }
        }

        return self::PLACEMENT_HEADER;
    }

    /**
     * @param string[] $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }
}

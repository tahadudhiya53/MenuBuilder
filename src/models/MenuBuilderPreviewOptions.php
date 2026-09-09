<?php

namespace Tahadudhiya\MenuBuilder\models;

use DateTime;
use DateTimeZone;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\visibility\VisibilityContext;

/**
 * What one control-panel preview simulates: a device width, an audience, a site, and where the menu
 * is presented.
*/
class MenuBuilderPreviewOptions
{
    public const DEVICE_DESKTOP = 'desktop';
    public const DEVICE_MOBILE = 'mobile';

    public const DEVICES = [self::DEVICE_DESKTOP, self::DEVICE_MOBILE];

    /**
     * Rendered in the page's masthead, the usual home of a primary navigation.
    */
    public const PLACEMENT_HEADER = 'header';

    /**
     * Rendered in the page's footer — stacked into columns, fully expanded, the way footers are.
    */
    public const PLACEMENT_FOOTER = 'footer';

    /**
     * Rendered in both regions, so the default preview demonstrates both common treatments.
    */
    public const PLACEMENT_BOTH = 'both';

    public const PLACEMENTS = [self::PLACEMENT_BOTH, self::PLACEMENT_HEADER, self::PLACEMENT_FOOTER];

    /**
     * An anonymous visitor: `loggedOut` passes, `loggedIn`/`userGroup` items are hidden.
    */
    public const AUDIENCE_LOGGED_OUT = 'loggedOut';

    /**
     * Any signed-in user belonging to no group in particular.
    */
    public const AUDIENCE_LOGGED_IN = 'loggedIn';

    /**
     * A signed-in user in the selected user group(s).
    */
    public const AUDIENCE_USER_GROUP = 'userGroup';

    public const AUDIENCES = [self::AUDIENCE_LOGGED_OUT, self::AUDIENCE_LOGGED_IN, self::AUDIENCE_USER_GROUP];

    public function __construct(
        public readonly string $device = self::DEVICE_DESKTOP,
        /**
         * Where on the mock page the navigation is shown — presentation only, never stored.
        */
        public readonly string $placement = self::PLACEMENT_BOTH,
        public readonly string $audience = self::AUDIENCE_LOGGED_OUT,
        /** @var int[] Meaningful only when `audience` is `userGroup`. */
        public readonly array $userGroupIds = [],
        public readonly ?int $siteId = null,
    ) {
    }

    /**
     * Builds the options for a preview request out of raw query params.
     *
     * @param array<mixed,mixed> $params
     * @param int[] $allowedSiteIds
     * @param int[] $allowedUserGroupIds
    */
    public static function normalize(
        array $params,
        array $allowedSiteIds = [],
        array $allowedUserGroupIds = [],
        ?int $defaultSiteId = null,
    ): self {
        $audience = self::oneOf($params['audience'] ?? null, self::AUDIENCES, self::AUDIENCE_LOGGED_OUT);
        $groupIds = [];

        if ($audience === self::AUDIENCE_USER_GROUP) {
            $posted = $params['userGroupIds'] ?? null;

            if (is_array($posted)) {
                // Craft's checkboxSelect posts an empty padding value so that an all-unchecked set
                // still arrives as a list.
                $posted = array_values(array_filter($posted, static fn(mixed $value): bool => $value !== ''));
            }

            // strictIdList() rather than normalizeIdList(): these IDs decide which group-restricted
            // items the preview reveals, so a `true` or a `"3abc"` must not intval its way into
            // meaning group 1 or group 3 (see ConfigHelper::strictIdList()).
            $requested = ConfigHelper::strictIdList($posted) ?? [];
            $groupIds = array_values(array_intersect($requested, $allowedUserGroupIds));

            // "A user group" with no group the current user may preview is not a narrower audience
            // of its own — it is exactly "some logged-in user".
            if ($groupIds === []) {
                $audience = self::AUDIENCE_LOGGED_IN;
            }
        }

        return new self(
            device: self::oneOf($params['device'] ?? null, self::DEVICES, self::DEVICE_DESKTOP),
            placement: self::oneOf(
                $params['placement'] ?? null,
                self::PLACEMENTS,
                self::PLACEMENT_BOTH
            ),
            audience: $audience,
            userGroupIds: $groupIds,
            siteId: self::resolveSiteId($params['siteId'] ?? null, $allowedSiteIds, $defaultSiteId),
        );
    }

    /**
     * The simulated site, or the caller's default when the requested one isn't one this user may
     * preview.
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
     * The VisibilityContext this simulation stands for.
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
        return $this->placement !== self::PLACEMENT_HEADER;
    }

    public function isHeader(): bool
    {
        return $this->placement !== self::PLACEMENT_FOOTER;
    }

    public function isBoth(): bool
    {
        return $this->placement === self::PLACEMENT_BOTH;
    }

    /** @param string[] $allowed */
    private static function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }
}

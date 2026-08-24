<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use DateTime;
use DateTimeZone;

/**
 * Everything a VisibilityRuleInterface needs to evaluate — built once per
 * request/render, never cached (see MenuBuilderCacheService docblock).
 * Deliberately carries plain scalars/arrays rather than live Craft element
 * objects (User/Site) — rules only ever need these few facts, and this keeps
 * the whole visibility layer testable without a booted Craft app.
 */
class VisibilityContext
{
    public function __construct(
        public readonly bool $isLoggedIn,
        /** @var int[] */
        public readonly array $userGroupIds,
        public readonly ?int $currentSiteId,
        public readonly DateTime $now,
        public readonly ?string $environment,
        /**
         * The application's configured timezone (e.g. Craft's `timezone`
         * general config setting), used to interpret naive date-range bounds
         * consistently rather than relying on PHP's ambient default
         * timezone. Falls back to `now`'s own timezone when not supplied.
         */
        public readonly ?DateTimeZone $timezone = null,
    ) {
    }
}

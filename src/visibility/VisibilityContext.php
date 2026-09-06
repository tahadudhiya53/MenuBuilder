<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use DateTime;
use DateTimeZone;

/**
 * Everything a VisibilityRuleInterface needs to evaluate — built once per request/render, never
 * cached (see MenuBuilderCacheService docblock).
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
         * The application's configured timezone (e.g.
        */
        public readonly ?DateTimeZone $timezone = null,
    ) {
    }
}

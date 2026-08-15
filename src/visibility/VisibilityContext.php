<?php

namespace Tahadudhiya\MenuBuilder\visibility;

use DateTime;

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
    ) {
    }
}

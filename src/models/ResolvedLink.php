<?php

namespace Tahadudhiya\MenuBuilder\models;

/**
 * The outcome of resolving a MenuBuilderItem's link type into an actual
 * (or unavailable) destination. Never persisted — computed fresh on every
 * resolve pass so deleted/disabled/moved elements are handled naturally.
 */
class ResolvedLink
{
    public function __construct(
        public readonly ?string $url = null,
        public readonly bool $isAvailable = true,
        /** The linked element's own title, used when the item has no explicit title override. */
        public readonly ?string $label = null,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(url: null, isAvailable: false);
    }

    public static function to(string $url, ?string $label = null): self
    {
        return new self(url: $url, isAvailable: true, label: $label);
    }

    public static function none(): self
    {
        return new self(url: null, isAvailable: true);
    }
}

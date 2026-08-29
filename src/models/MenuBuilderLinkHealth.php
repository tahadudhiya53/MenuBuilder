<?php

namespace Tahadudhiya\MenuBuilder\models;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use Tahadudhiya\MenuBuilder\linktypes\AnchorLinkResolver;
use Tahadudhiya\MenuBuilder\linktypes\ElementLinkResolver;
use Tahadudhiya\MenuBuilder\services\MenuBuilderDynamicNavigationService;

/**
 * Why one menu item's link would (or wouldn't) work, as the CP explains it
 * to an editor. Computed fresh, never persisted — the same reasoning as
 * {@see ResolvedLink}, and for the same targets: there is no stored
 * "is this broken" flag that could disagree with what the front end does.
 *
 * The two halves of this class are deliberately split:
 *
 * 1. **Classification** — the `for*()` statics below. Pure, so the whole
 *    mapping from "what the element service found" to "what the editor is
 *    told" is unit-testable without a booted Craft app, exactly like
 *    {@see ElementLinkResolver::isPubliclyAvailable()} (which this delegates
 *    the healthy/not-healthy call to, so the warning and the rendered menu
 *    can never disagree about what counts as available).
 * 2. **Wording** — `label()` / `message()` / `consequence()`. These are the
 *    class's only output, and they are built from the *status* alone plus
 *    the item's own fallback configuration. Nothing read off the linked
 *    element — no title, no URI, no slug, no ID, no section or volume name —
 *    ever reaches them: a disabled or unpublished element is content the
 *    viewer of this screen has not necessarily been granted, and a menu
 *    warning is not an authorization check. MenuBuilderLinkHealthTest pins
 *    that by construction (the constructor takes no element data at all).
 */
class MenuBuilderLinkHealth
{
    /** The link resolves to a usable destination — nothing to warn about. */
    public const STATUS_HEALTHY = 'healthy';

    /** The linked element doesn't exist any more (hard-deleted, or in the trash). */
    public const STATUS_MISSING = 'missing';

    /** The element exists, but not on the site being edited. */
    public const STATUS_NOT_ON_SITE = 'notOnSite';

    /** The element exists here but is disabled — globally, or for this site. */
    public const STATUS_DISABLED = 'disabled';

    /** The element exists and is enabled, but isn't live: pending, or expired. */
    public const STATUS_UNPUBLISHED = 'unpublished';

    /** The element is available but has no public URL (no URI format, private volume). */
    public const STATUS_NO_URL = 'noUrl';

    /** A custom URL or anchor target that isn't a valid, safe link. */
    public const STATUS_INVALID_URL = 'invalidUrl';

    /** A `dynamic` item whose source config can't be used, or whose source container is gone. */
    public const STATUS_INVALID_SOURCE = 'invalidSource';

    public const STATUSES = [
        self::STATUS_HEALTHY,
        self::STATUS_MISSING,
        self::STATUS_NOT_ON_SITE,
        self::STATUS_DISABLED,
        self::STATUS_UNPUBLISHED,
        self::STATUS_NO_URL,
        self::STATUS_INVALID_URL,
        self::STATUS_INVALID_SOURCE,
    ];

    /**
     * The statuses where the item points at an element that isn't there to
     * point at any more, and the editor is therefore offered the recovery
     * actions (relink, fallback URL, disable, remove). Every other unhealthy
     * status describes something still fixable in place — re-enable the
     * element, publish it, correct the URL — so offering "remove this item"
     * for them would push a destructive action at a temporary state.
     */
    public const RECOVERY_STATUSES = [
        self::STATUS_MISSING,
        self::STATUS_NOT_ON_SITE,
    ];

    /**
     * @param string $status One of {@see STATUSES}.
     * @param string $fallbackBehavior The item's own `fallbackBehavior` — decides {@see consequence()}.
     * @param bool $itemEnabled Whether the *menu item* is enabled (a disabled item renders nowhere regardless).
     * @param bool $fallbackUsable Whether the item's fallback URL would actually survive
     *        ElementLinkResolver::fallbackFor()'s re-check. A configured-but-unsafe fallback URL
     *        resolves to nothing, and saying "it falls back to your URL" would be a lie.
     */
    public function __construct(
        public readonly string $status = self::STATUS_HEALTHY,
        public readonly string $fallbackBehavior = MenuBuilderItem::FALLBACK_HIDE,
        public readonly bool $itemEnabled = true,
        public readonly bool $fallbackUsable = false,
    ) {
    }

    public static function healthy(): self
    {
        return new self();
    }

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }

    /** Whether this item should be offered the "the element is gone" recovery actions. */
    public function needsElementRecovery(): bool
    {
        return in_array($this->status, self::RECOVERY_STATUSES, true);
    }

    /**
     * Which Craft element status maps to which health status, for an element
     * that was found on the site being edited.
     *
     * "Available" is not re-decided here: {@see ElementLinkResolver::isPubliclyAvailable()}
     * is the single authority for that, so an element the front end would
     * happily link to can never be flagged in the CP (or vice versa). This
     * only says *why* an unavailable one is unavailable.
     *
     * @param class-string $elementClass
     */
    public static function forElementStatus(string $elementClass, ?string $status, bool $hasUrl): string
    {
        if (ElementLinkResolver::isPubliclyAvailable($elementClass, $status)) {
            // Live, but nothing to link to: an entry in a section with no URI
            // format, or an asset in a volume with no public base URL. The
            // resolver falls back on exactly this case (`getUrl() === null`),
            // so it is a warning, not a healthy link.
            return $hasUrl ? self::STATUS_HEALTHY : self::STATUS_NO_URL;
        }

        return match ($status) {
            // `disabled` covers both "disabled everywhere" and "enabled, but
            // not for this site" — Element::getStatus() collapses the two, and
            // the fix (an editor turning it back on) is the same either way.
            Element::STATUS_DISABLED => self::STATUS_DISABLED,
            Entry::STATUS_PENDING, Entry::STATUS_EXPIRED => self::STATUS_UNPUBLISHED,
            // Archived elements are gone as far as a menu is concerned, and
            // are what a "deleted" element looks like on the few paths that
            // archive rather than trash.
            Element::STATUS_ARCHIVED => self::STATUS_MISSING,
            // A null status means the element couldn't report one at all; an
            // unrecognised one means a custom element type or a future Craft
            // status. Both fail *visible* — an unexplained silent omission
            // from the front end is the thing this phase exists to stop.
            default => self::STATUS_UNPUBLISHED,
        };
    }

    /**
     * The health of an item whose link needs no element lookup: custom URLs,
     * anchors, structural types, and the *shape* of a dynamic source config.
     * Returns null for the element-backed types, which only
     * {@see MenuBuilderLinkHealthService} can answer.
     *
     * Every check delegates to the same validator the resolver and the model
     * use ({@see MenuBuilderItem::isPermissiveUrl()},
     * {@see AnchorLinkResolver::anchorTarget()},
     * {@see MenuBuilderDynamicNavigationService::normalizeConfig()}), so a
     * value the front end would refuse to emit is a value the CP flags.
     */
    public static function forNonElementItem(MenuBuilderItem $item): ?string
    {
        return match ($item->type) {
            MenuBuilderItem::TYPE_URL => $item->customUrl !== null && MenuBuilderItem::isPermissiveUrl($item->customUrl)
                ? self::STATUS_HEALTHY
                : self::STATUS_INVALID_URL,
            MenuBuilderItem::TYPE_ANCHOR => self::anchorStatus($item),
            MenuBuilderItem::TYPE_DYNAMIC => is_array($item->metadata['dynamicSource'] ?? null)
                && MenuBuilderDynamicNavigationService::normalizeConfig($item->metadata['dynamicSource']) !== null
                    ? self::STATUS_HEALTHY
                    : self::STATUS_INVALID_SOURCE,
            // A heading or a separator is *supposed* to have no destination.
            MenuBuilderItem::TYPE_NONCLICKABLE, MenuBuilderItem::TYPE_SEPARATOR => self::STATUS_HEALTHY,
            default => null,
        };
    }

    private static function anchorStatus(MenuBuilderItem $item): string
    {
        $target = AnchorLinkResolver::anchorTarget($item);

        return $target !== null && MenuBuilderItem::isValidAnchorTarget($target)
            ? self::STATUS_HEALTHY
            : self::STATUS_INVALID_URL;
    }

    /**
     * Whether the item's configured fallback URL would actually be emitted —
     * the same re-check {@see ElementLinkResolver::fallbackFor()} performs,
     * shared rather than restated so the CP can't promise a fallback the
     * resolver then refuses.
     */
    public static function isFallbackUsable(MenuBuilderItem $item): bool
    {
        return $item->fallbackBehavior === MenuBuilderItem::FALLBACK_FALLBACK_URL
            && $item->fallbackUrl !== null
            && MenuBuilderItem::isPermissiveUrl($item->fallbackUrl);
    }

    /** Short badge text. Says what is wrong, never which content it is. */
    public function label(): string
    {
        return match ($this->status) {
            self::STATUS_MISSING => Craft::t('menu-builder', 'Linked content missing'),
            self::STATUS_NOT_ON_SITE => Craft::t('menu-builder', 'Not on this site'),
            self::STATUS_DISABLED => Craft::t('menu-builder', 'Linked content disabled'),
            self::STATUS_UNPUBLISHED => Craft::t('menu-builder', 'Linked content unpublished'),
            self::STATUS_NO_URL => Craft::t('menu-builder', 'Linked content has no URL'),
            self::STATUS_INVALID_URL => Craft::t('menu-builder', 'Invalid link'),
            self::STATUS_INVALID_SOURCE => Craft::t('menu-builder', 'Dynamic source unavailable'),
            default => Craft::t('menu-builder', 'OK'),
        };
    }

    /**
     * One sentence of explanation. Deliberately generic about the target:
     * "the linked content", never its title, URI or ID — see the class
     * docblock.
     */
    public function message(): string
    {
        return match ($this->status) {
            self::STATUS_MISSING => Craft::t('menu-builder', 'The entry, category or asset this item links to no longer exists.'),
            self::STATUS_NOT_ON_SITE => Craft::t('menu-builder', 'The linked content isn’t available on the site you’re looking at.'),
            self::STATUS_DISABLED => Craft::t('menu-builder', 'The linked content is disabled, either everywhere or for this site.'),
            self::STATUS_UNPUBLISHED => Craft::t('menu-builder', 'The linked content isn’t published — it is pending, expired, or otherwise not live.'),
            self::STATUS_NO_URL => Craft::t('menu-builder', 'The linked content has no public URL to link to.'),
            self::STATUS_INVALID_URL => Craft::t('menu-builder', 'This item’s URL or anchor target isn’t a valid, safe link.'),
            self::STATUS_INVALID_SOURCE => Craft::t('menu-builder', 'This item’s dynamic navigation source is missing or misconfigured.'),
            default => Craft::t('menu-builder', 'This item’s link resolves normally.'),
        };
    }

    /**
     * What the front end actually does about it right now — the question an
     * editor asks second, and the one a bare warning badge never answered.
     * Derived from the item's own fallback configuration, which is the thing
     * the resolver consults ({@see MenuBuilderResolver::convert()}).
     */
    public function consequence(): string
    {
        if ($this->isHealthy()) {
            return '';
        }

        if (!$this->itemEnabled) {
            return Craft::t('menu-builder', 'This menu item is disabled, so it renders nowhere on the front end either way.');
        }

        return match ($this->fallbackBehavior) {
            MenuBuilderItem::FALLBACK_DISABLE_LINK => Craft::t('menu-builder', 'The item still appears on the front end, as plain text with no link.'),
            MenuBuilderItem::FALLBACK_FALLBACK_URL => $this->fallbackUsable
                ? Craft::t('menu-builder', 'The item still appears on the front end, linked to its fallback URL.')
                : Craft::t('menu-builder', 'The item is set to use a fallback URL, but that URL isn’t usable — it appears as plain text with no link.'),
            default => Craft::t('menu-builder', 'The item is hidden on the front end until this is fixed.'),
        };
    }

    /**
     * Counts per status across a set of items, plus the total that need
     * attention — what the dashboard's summary line is built from. Pure and
     * static so the summary can be tested without a booted app.
     *
     * @param array<int,self> $healths
     * @return array{total:int,byStatus:array<string,int>}
     */
    public static function summarize(array $healths): array
    {
        $byStatus = [];
        $total = 0;

        foreach ($healths as $health) {
            if ($health->isHealthy()) {
                continue;
            }

            $byStatus[$health->status] = ($byStatus[$health->status] ?? 0) + 1;
            $total++;
        }

        return ['total' => $total, 'byStatus' => $byStatus];
    }

    /**
     * The element classes this model classifies, keyed by the item type that
     * uses them — the same mapping MenuBuilderItemService's orphan lookup
     * used to keep privately. Public so the service and the tests share one
     * copy of it.
     *
     * @return array<string,class-string>
     */
    public static function elementClasses(): array
    {
        return [
            MenuBuilderItem::TYPE_ENTRY => Entry::class,
            MenuBuilderItem::TYPE_CATEGORY => Category::class,
            MenuBuilderItem::TYPE_ASSET => Asset::class,
        ];
    }
}

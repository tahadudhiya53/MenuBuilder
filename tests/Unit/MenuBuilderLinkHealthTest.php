<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderLinkHealth;

/**
 * Link health: what the CP tells an editor about a menu item whose link
 * doesn't work, and what it is careful not to tell them.
 *
 * The service half (two queries per element type, current site vs. any site)
 * needs a booted Craft app and a database and so isn't covered here — see
 * "manual testing required" in the phase report. Everything that *decides*
 * anything is pure and is covered: the element-status mapping, the
 * non-element checks, the front-end consequence, the summary, and the
 * no-disclosure property.
 */
class MenuBuilderLinkHealthTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Element-backed items
    // ---------------------------------------------------------------------

    public function testALiveEntryWithAUrlIsHealthy(): void
    {
        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_HEALTHY,
            MenuBuilderLinkHealth::forElementStatus(Entry::class, Entry::STATUS_LIVE, true)
        );
    }

    /**
     * The element resolves, but there is nothing to put in an href — an entry
     * in a section with no URI format, an asset in a volume with no public
     * base URL. ElementLinkResolver falls back on exactly this case, so the
     * CP has to flag it rather than call it healthy.
     */
    public function testALiveElementWithNoUrlIsFlagged(): void
    {
        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_NO_URL,
            MenuBuilderLinkHealth::forElementStatus(Entry::class, Entry::STATUS_LIVE, false)
        );

        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_NO_URL,
            MenuBuilderLinkHealth::forElementStatus(Asset::class, Asset::STATUS_ENABLED, false)
        );
    }

    public function testADisabledElementIsReportedAsDisabled(): void
    {
        foreach ([Entry::class, Category::class, Asset::class] as $elementClass) {
            $this->assertSame(
                MenuBuilderLinkHealth::STATUS_DISABLED,
                MenuBuilderLinkHealth::forElementStatus($elementClass, Element::STATUS_DISABLED, true),
                $elementClass
            );
        }
    }

    /**
     * Pending (post date in the future) and expired (expiry date passed) are
     * both "enabled but not live" — the editor's fix is a date, not a switch,
     * which is why they don't share the "disabled" wording.
     */
    public function testAPendingOrExpiredEntryIsReportedAsUnpublished(): void
    {
        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_UNPUBLISHED,
            MenuBuilderLinkHealth::forElementStatus(Entry::class, Entry::STATUS_PENDING, true)
        );

        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_UNPUBLISHED,
            MenuBuilderLinkHealth::forElementStatus(Entry::class, Entry::STATUS_EXPIRED, true)
        );
    }

    public function testAnArchivedElementIsReportedAsMissing(): void
    {
        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_MISSING,
            MenuBuilderLinkHealth::forElementStatus(Entry::class, Element::STATUS_ARCHIVED, true)
        );
    }

    /**
     * An unknown or absent status must not read as healthy: the front end
     * would drop or unlink the item, and a silent omission is the thing this
     * screen exists to explain.
     */
    public function testAnUnknownStatusFailsVisible(): void
    {
        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_UNPUBLISHED,
            MenuBuilderLinkHealth::forElementStatus(Entry::class, null, true)
        );

        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_UNPUBLISHED,
            MenuBuilderLinkHealth::forElementStatus(Entry::class, 'somethingNew', true)
        );
    }

    /**
     * A restored (un-trashed, re-enabled) element needs no invalidation step
     * of its own: health is recomputed from the element's current status
     * every time, so the same call that said "missing" says "healthy" the
     * moment the element is back.
     */
    public function testARestoredElementIsHealthyAgain(): void
    {
        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_DISABLED,
            MenuBuilderLinkHealth::forElementStatus(Category::class, Element::STATUS_DISABLED, true)
        );

        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_HEALTHY,
            MenuBuilderLinkHealth::forElementStatus(Category::class, Category::STATUS_ENABLED, true)
        );
    }

    /**
     * The CP must never flag a link the front end would happily emit, or pass
     * one it would refuse — both come from
     * ElementLinkResolver::isPubliclyAvailable(), and this is the pin that
     * they still share it.
     */
    public function testAvailabilityAgreesWithTheResolver(): void
    {
        // An entry that is merely "enabled" (not live) is not linkable.
        $this->assertNotSame(
            MenuBuilderLinkHealth::STATUS_HEALTHY,
            MenuBuilderLinkHealth::forElementStatus(Entry::class, Element::STATUS_ENABLED, true)
        );

        // …but for a category, "enabled" is exactly the available state.
        $this->assertSame(
            MenuBuilderLinkHealth::STATUS_HEALTHY,
            MenuBuilderLinkHealth::forElementStatus(Category::class, Element::STATUS_ENABLED, true)
        );
    }

    public function testElementBackedItemsAreLeftToTheService(): void
    {
        foreach (MenuBuilderItem::ELEMENT_TYPES as $type) {
            $item = new MenuBuilderItem(['type' => $type, 'elementId' => 7]);

            $this->assertNull(MenuBuilderLinkHealth::forNonElementItem($item), $type);
        }
    }

    // ---------------------------------------------------------------------
    // Custom URLs, anchors, structural types, dynamic sources
    // ---------------------------------------------------------------------

    /**
     * External URLs are judged on their shape only — this phase adds no HTTP
     * crawling, so a reachable and an unreachable https:// URL are both
     * healthy here.
     *
     * @dataProvider healthyUrlProvider
     */
    public function testAValidCustomUrlIsHealthy(string $url): void
    {
        $item = new MenuBuilderItem(['type' => MenuBuilderItem::TYPE_URL, 'customUrl' => $url]);

        $this->assertSame(MenuBuilderLinkHealth::STATUS_HEALTHY, MenuBuilderLinkHealth::forNonElementItem($item));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function healthyUrlProvider(): array
    {
        return [
            'external' => ['https://example.com/page'],
            'external with query' => ['https://example.com/page?ref=nav'],
            'root-relative' => ['/about/team'],
            'fragment' => ['#main'],
            'mailto' => ['mailto:hello@example.com'],
            'tel' => ['tel:+15550100'],
        ];
    }

    /**
     * @dataProvider invalidUrlProvider
     */
    public function testAnUnsafeOrMalformedCustomUrlIsFlagged(?string $url): void
    {
        $item = new MenuBuilderItem(['type' => MenuBuilderItem::TYPE_URL, 'customUrl' => $url]);

        $this->assertSame(MenuBuilderLinkHealth::STATUS_INVALID_URL, MenuBuilderLinkHealth::forNonElementItem($item));
    }

    /**
     * The unsafe ones matter as much as the malformed ones: a `javascript:`
     * URL in the database resolves to nothing on the front end
     * (UrlLinkResolver re-checks it), so without this the item would vanish
     * from the menu with no explanation anywhere in the CP.
     *
     * @return array<string,array{string|null}>
     */
    public static function invalidUrlProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'bare word' => ['not a url'],
            'javascript' => ['javascript:alert(1)'],
            'obfuscated javascript' => ["java\tscript:alert(1)"],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'lone hash' => ['#'],
        ];
    }

    public function testAnchorTargetsAreCheckedTheSameWayTheResolverReadsThem(): void
    {
        $valid = new MenuBuilderItem(['type' => MenuBuilderItem::TYPE_ANCHOR, 'customUrl' => '#pricing']);
        $this->assertSame(MenuBuilderLinkHealth::STATUS_HEALTHY, MenuBuilderLinkHealth::forNonElementItem($valid));

        // The resolver falls back to `handle` when there's no anchor field.
        $fromHandle = new MenuBuilderItem(['type' => MenuBuilderItem::TYPE_ANCHOR, 'handle' => 'pricing']);
        $this->assertSame(MenuBuilderLinkHealth::STATUS_HEALTHY, MenuBuilderLinkHealth::forNonElementItem($fromHandle));

        $none = new MenuBuilderItem(['type' => MenuBuilderItem::TYPE_ANCHOR]);
        $this->assertSame(MenuBuilderLinkHealth::STATUS_INVALID_URL, MenuBuilderLinkHealth::forNonElementItem($none));

        $malformed = new MenuBuilderItem(['type' => MenuBuilderItem::TYPE_ANCHOR, 'customUrl' => 'two words']);
        $this->assertSame(MenuBuilderLinkHealth::STATUS_INVALID_URL, MenuBuilderLinkHealth::forNonElementItem($malformed));
    }

    /** A heading and a separator are supposed to have no destination. */
    public function testStructuralTypesAreAlwaysHealthy(): void
    {
        foreach ([MenuBuilderItem::TYPE_NONCLICKABLE, MenuBuilderItem::TYPE_SEPARATOR] as $type) {
            $item = new MenuBuilderItem(['type' => $type]);

            $this->assertSame(MenuBuilderLinkHealth::STATUS_HEALTHY, MenuBuilderLinkHealth::forNonElementItem($item), $type);
        }
    }

    public function testADynamicItemIsCheckedAgainstTheSameConfigRulesTheQueryUses(): void
    {
        $usable = new MenuBuilderItem([
            'type' => MenuBuilderItem::TYPE_DYNAMIC,
            'metadata' => ['dynamicSource' => ['sourceType' => 'entries', 'sourceId' => 3]],
        ]);
        $this->assertSame(MenuBuilderLinkHealth::STATUS_HEALTHY, MenuBuilderLinkHealth::forNonElementItem($usable));

        foreach ([
            'no config' => [],
            'unknown source type' => ['dynamicSource' => ['sourceType' => 'users', 'sourceId' => 3]],
            'no source id' => ['dynamicSource' => ['sourceType' => 'entries']],
            'not an array' => ['dynamicSource' => 'entries:3'],
        ] as $label => $metadata) {
            $item = new MenuBuilderItem(['type' => MenuBuilderItem::TYPE_DYNAMIC, 'metadata' => $metadata]);

            $this->assertSame(
                MenuBuilderLinkHealth::STATUS_INVALID_SOURCE,
                MenuBuilderLinkHealth::forNonElementItem($item),
                $label
            );
        }
    }

    // ---------------------------------------------------------------------
    // What the front end does about it
    // ---------------------------------------------------------------------

    public function testTheConsequenceFollowsTheItemsFallbackBehaviour(): void
    {
        $hide = new MenuBuilderLinkHealth(MenuBuilderLinkHealth::STATUS_MISSING, MenuBuilderItem::FALLBACK_HIDE);
        $this->assertStringContainsString('hidden', $hide->consequence());

        $disableLink = new MenuBuilderLinkHealth(MenuBuilderLinkHealth::STATUS_MISSING, MenuBuilderItem::FALLBACK_DISABLE_LINK);
        $this->assertStringContainsString('plain text', $disableLink->consequence());

        $fallbackUrl = new MenuBuilderLinkHealth(
            MenuBuilderLinkHealth::STATUS_MISSING,
            MenuBuilderItem::FALLBACK_FALLBACK_URL,
            fallbackUsable: true
        );
        $this->assertStringContainsString('fallback URL', $fallbackUrl->consequence());
    }

    /**
     * A fallback URL the resolver would refuse (unsafe scheme, empty) must
     * not be described as a working fallback — ElementLinkResolver re-checks
     * it and emits nothing, so the item renders as plain text.
     */
    public function testAnUnusableFallbackUrlIsNotPromised(): void
    {
        $health = new MenuBuilderLinkHealth(
            MenuBuilderLinkHealth::STATUS_MISSING,
            MenuBuilderItem::FALLBACK_FALLBACK_URL,
            fallbackUsable: false
        );

        $this->assertStringContainsString('isn’t usable', $health->consequence());
    }

    public function testFallbackUsabilityUsesTheSameCheckTheResolverDoes(): void
    {
        $usable = new MenuBuilderItem([
            'fallbackBehavior' => MenuBuilderItem::FALLBACK_FALLBACK_URL,
            'fallbackUrl' => '/somewhere-else',
        ]);
        $this->assertTrue(MenuBuilderLinkHealth::isFallbackUsable($usable));

        $unsafe = new MenuBuilderItem([
            'fallbackBehavior' => MenuBuilderItem::FALLBACK_FALLBACK_URL,
            'fallbackUrl' => 'javascript:alert(1)',
        ]);
        $this->assertFalse(MenuBuilderLinkHealth::isFallbackUsable($unsafe));

        $notConfigured = new MenuBuilderItem([
            'fallbackBehavior' => MenuBuilderItem::FALLBACK_HIDE,
            'fallbackUrl' => '/somewhere-else',
        ]);
        $this->assertFalse(MenuBuilderLinkHealth::isFallbackUsable($notConfigured));
    }

    /** A disabled item renders nowhere, so no fallback wording applies to it. */
    public function testADisabledItemSaysSoInsteadOfDescribingAFallback(): void
    {
        $health = new MenuBuilderLinkHealth(
            MenuBuilderLinkHealth::STATUS_MISSING,
            MenuBuilderItem::FALLBACK_DISABLE_LINK,
            itemEnabled: false
        );

        $this->assertStringContainsString('disabled', $health->consequence());
        $this->assertStringNotContainsString('plain text', $health->consequence());
    }

    public function testAHealthyItemHasNoConsequenceToReport(): void
    {
        $this->assertSame('', MenuBuilderLinkHealth::healthy()->consequence());
        $this->assertTrue(MenuBuilderLinkHealth::healthy()->isHealthy());
    }

    // ---------------------------------------------------------------------
    // Recovery actions
    // ---------------------------------------------------------------------

    /**
     * The remove / disable / replace / fallback affordances are offered only
     * where the element is genuinely gone. A disabled or unpublished element
     * comes back on its own, and pushing "delete this menu item" at a state
     * that resolves itself is how a menu loses items it should have kept.
     */
    public function testRecoveryActionsAreOfferedOnlyWhenTheElementIsGone(): void
    {
        $offered = [];

        foreach (MenuBuilderLinkHealth::STATUSES as $status) {
            if ((new MenuBuilderLinkHealth($status))->needsElementRecovery()) {
                $offered[] = $status;
            }
        }

        $this->assertSame([
            MenuBuilderLinkHealth::STATUS_MISSING,
            MenuBuilderLinkHealth::STATUS_NOT_ON_SITE,
        ], $offered);
    }

    // ---------------------------------------------------------------------
    // Summary
    // ---------------------------------------------------------------------

    public function testTheSummaryCountsOnlyItemsThatNeedAttention(): void
    {
        $summary = MenuBuilderLinkHealth::summarize([
            1 => MenuBuilderLinkHealth::healthy(),
            2 => new MenuBuilderLinkHealth(MenuBuilderLinkHealth::STATUS_MISSING),
            3 => new MenuBuilderLinkHealth(MenuBuilderLinkHealth::STATUS_MISSING),
            4 => new MenuBuilderLinkHealth(MenuBuilderLinkHealth::STATUS_INVALID_URL),
            5 => MenuBuilderLinkHealth::healthy(),
        ]);

        $this->assertSame(3, $summary['total']);
        $this->assertSame([
            MenuBuilderLinkHealth::STATUS_MISSING => 2,
            MenuBuilderLinkHealth::STATUS_INVALID_URL => 1,
        ], $summary['byStatus']);
    }

    public function testAHealthyMenuSummarizesToNothing(): void
    {
        $summary = MenuBuilderLinkHealth::summarize([1 => MenuBuilderLinkHealth::healthy()]);

        $this->assertSame(0, $summary['total']);
        $this->assertSame([], $summary['byStatus']);
    }

    // ---------------------------------------------------------------------
    // Disclosure
    // ---------------------------------------------------------------------

    /**
     * The warning must not leak the content it is about. An editor who can
     * see a menu is not thereby entitled to the title, URI, slug or ID of a
     * disabled, unpublished or deleted element — a menu warning is not an
     * authorization check, and the CP tree is visible to anyone with
     * `menuBuilder:view`.
     *
     * The guarantee is structural rather than a string audit: the object
     * carries no element data to leak. If a future change adds an element
     * title or URI to it "just for the message", this test fails.
     */
    public function testTheHealthObjectCarriesNoElementData(): void
    {
        $properties = array_map(
            static fn($property) => $property->getName(),
            (new ReflectionClass(MenuBuilderLinkHealth::class))->getProperties()
        );

        sort($properties);

        $this->assertSame(
            ['fallbackBehavior', 'fallbackUsable', 'itemEnabled', 'status'],
            $properties,
            'MenuBuilderLinkHealth must hold nothing but a status and the item’s own fallback configuration.'
        );
    }

    /**
     * The other half of the same guarantee: the wording depends on the status
     * alone, so two items pointing at completely different content get
     * byte-identical text.
     */
    public function testTheWordingIsIdenticalForEveryItemInTheSameState(): void
    {
        $first = new MenuBuilderLinkHealth(MenuBuilderLinkHealth::STATUS_MISSING, MenuBuilderItem::FALLBACK_HIDE);
        $second = new MenuBuilderLinkHealth(MenuBuilderLinkHealth::STATUS_MISSING, MenuBuilderItem::FALLBACK_HIDE);

        $this->assertSame($first->label(), $second->label());
        $this->assertSame($first->message(), $second->message());
        $this->assertSame($first->consequence(), $second->consequence());
    }

    /**
     * Every status is explained, and no explanation contains anything that
     * could only have come from the element: no digits (IDs, dates), no
     * slashes (URIs, URLs), no angle brackets.
     */
    public function testEveryStatusHasSafeGenericWording(): void
    {
        foreach (MenuBuilderLinkHealth::STATUSES as $status) {
            $health = new MenuBuilderLinkHealth($status);

            $this->assertNotSame('', $health->label(), $status);
            $this->assertNotSame('', $health->message(), $status);

            foreach ([$health->label(), $health->message(), $health->consequence()] as $text) {
                $this->assertDoesNotMatchRegularExpression('/[0-9\/<>]/', $text, $status . ': ' . $text);
            }
        }
    }

    // ---------------------------------------------------------------------
    // CP wiring
    // ---------------------------------------------------------------------

    private const TEMPLATE_DIR = __DIR__ . '/../../src/templates/';

    /**
     * The dashboard row must render the health badge from the health object's
     * own wording — not from a second copy of the strings in Twig, which is
     * how the CP and the resolver drift apart.
     */
    public function testTheTreeRowRendersHealthFromTheHealthObject(): void
    {
        $source = file_get_contents(self::TEMPLATE_DIR . 'dashboard/_items.twig');

        $this->assertStringContainsString('itemHealth[item.id]', $source);
        $this->assertStringContainsString('health.isHealthy()', $source);
        $this->assertStringContainsString('health.label()', $source);
        $this->assertStringContainsString('health.message()', $source);
        $this->assertStringContainsString('health.needsElementRecovery()', $source);
    }

    /** The recovery entry opens the editor; it must not be a destructive shortcut. */
    public function testTheRecoveryActionOpensTheEditor(): void
    {
        $source = file_get_contents(self::TEMPLATE_DIR . 'dashboard/_items.twig');
        $position = strpos($source, 'health.needsElementRecovery()');

        $this->assertNotFalse($position);

        $block = substr($source, $position, 600);

        $this->assertStringContainsString('data-mb-action="edit"', $block);
        $this->assertStringNotContainsString('data-mb-action="delete"', $block);
    }

    public function testTheDashboardShowsAMenuWideSummary(): void
    {
        $source = file_get_contents(self::TEMPLATE_DIR . 'dashboard/index.twig');

        $this->assertStringContainsString('healthSummary.total', $source);
        $this->assertStringContainsString('itemHealth: itemHealth', $source);
    }

    /**
     * The editor names all four safe ways out of a missing element, and none
     * of them happens on its own — nothing in this plugin deletes a menu item
     * because the content it pointed at went away.
     */
    public function testTheEditorOffersTheSafeActionsWithoutTakingThem(): void
    {
        $source = file_get_contents(self::TEMPLATE_DIR . 'items/_fields.twig');

        $this->assertStringContainsString('health.needsElementRecovery()', $source);
        $this->assertStringContainsString('relink', $source);
        $this->assertStringContainsString('fallback URL', $source);
        $this->assertStringContainsString('disable this item', $source);
        $this->assertStringContainsString('delete it from the menu', $source);
    }
}

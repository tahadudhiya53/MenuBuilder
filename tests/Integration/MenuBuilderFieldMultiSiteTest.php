<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\base\Element;
use Tahadudhiya\MenuBuilder\models\MenuBuilderFieldValue;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * The Navigation field on a **real two-site install**.
 *
 * Three separate questions live here, and the suite keeps them apart because
 * they have different answers:
 *
 * 1. **Where the value lives** — shared across sites, or one per site. That's
 *    Craft's translation method, and the fixture has one instance of each.
 * 2. **Whether the selected menu is available on the element's site** — a
 *    MenuBuilder group-level site restriction, reported by
 *    `isAvailableForSite()` and (on a translatable field only) by validation.
 * 3. **Which site the tree is resolved for** — the site of the *current
 *    request*, which is the documented limitation this suite pins so that
 *    changing it has to be deliberate.
 */
class MenuBuilderFieldMultiSiteTest extends CraftIntegrationTestCase
{
    private int $originalSiteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSiteId = Craft::$app->getSites()->getCurrentSite()->id;
    }

    protected function tearDown(): void
    {
        // Site state is global; leaking it would make every later test depend
        // on execution order.
        Craft::$app->getSites()->setCurrentSite($this->originalSiteId);
        parent::tearDown();
    }

    private function primarySiteId(): int
    {
        return Craft::$app->getSites()->getPrimarySite()->id;
    }

    // ---------------------------------------------------------------------
    // 1. Shared vs per-site values
    // ---------------------------------------------------------------------

    /**
     * The default: one selection covers every site. This is what a single
     * shared navigation wants, and it means the value propagates to a new site
     * without anyone re-picking it.
     */
    public function testAnUntranslatableFieldHasTheSameSelectionOnEverySite(): void
    {
        $primary = self::pages($this->primarySiteId())->id(self::$entryIds['picks-main'])->one();
        $secondary = self::pages(self::$secondSiteId)->id(self::$entryIds['picks-main'])->one();

        $this->assertNotNull($secondary, 'The entry propagated to the second site.');
        $this->assertSame(
            $primary->getFieldValue(self::FIELD_HANDLE)->groupUid,
            $secondary->getFieldValue(self::FIELD_HANDLE)->groupUid,
        );
        $this->assertSame((string)self::$mainMenu->uid, $secondary->getFieldValue(self::FIELD_HANDLE)->groupUid);
    }

    /** And a shared field cannot be given a different value per site. */
    public function testAnUntranslatableFieldsValueIsCopiedBackAcrossSites(): void
    {
        $secondary = self::pages(self::$secondSiteId)->id(self::$entryIds['picks-main'])->one();
        $secondary->setFieldValue(self::FIELD_HANDLE, (string)self::$footerMenu->uid);
        $this->assertTrue(Craft::$app->getElements()->saveElement($secondary));

        $primary = self::pages($this->primarySiteId())->id(self::$entryIds['picks-main'])->one();

        $this->assertSame(
            (string)self::$footerMenu->uid,
            $primary->getFieldValue(self::FIELD_HANDLE)->groupUid,
            'A shared field is one value: editing it on either site changes both.'
        );

        // Restore.
        $primary->setFieldValue(self::FIELD_HANDLE, (string)self::$mainMenu->uid);
        $this->assertTrue(Craft::$app->getElements()->saveElement($primary));
    }

    /** A translatable instance is genuinely per-site. */
    public function testATranslatableFieldHoldsADifferentSelectionPerSite(): void
    {
        $secondary = self::pages(self::$secondSiteId)->id(self::$entryIds['picks-primary-only'])->one();
        $secondary->setFieldValue(self::PER_SITE_FIELD_HANDLE, (string)self::$footerMenu->uid);
        $this->assertTrue(Craft::$app->getElements()->saveElement($secondary));

        $primary = self::pages($this->primarySiteId())->id(self::$entryIds['picks-primary-only'])->one();

        $this->assertSame(
            (string)self::$mainMenu->uid,
            $primary->getFieldValue(self::PER_SITE_FIELD_HANDLE)->groupUid,
            'The primary site keeps its own selection…'
        );
        $this->assertSame(
            (string)self::$footerMenu->uid,
            self::pages(self::$secondSiteId)->id(self::$entryIds['picks-primary-only'])->one()
                ->getFieldValue(self::PER_SITE_FIELD_HANDLE)->groupUid,
            '…and the second site its own.'
        );
    }

    /** Each site's value is queryable independently. */
    public function testQueryingATranslatableFieldIsScopedToTheSiteQueried(): void
    {
        $this->assertSame(
            ['picks-primary-only'],
            self::slugsOf(
                self::pages($this->primarySiteId())
                    ->navigationPerSite((string)self::$mainMenu->uid)
                    ->all()
            )
        );

        $this->assertSame(
            [],
            self::pages(self::$secondSiteId)
                ->navigationPerSite((string)self::$mainMenu->uid)
                ->all(),
            'The second site holds a different selection, so it must not match.'
        );
    }

    // ---------------------------------------------------------------------
    // 2. Group-level site restriction
    // ---------------------------------------------------------------------

    public function testAvailabilityIsReportedForTheElementsOwnSite(): void
    {
        /** @var MenuBuilderFieldValue $onPrimary */
        $onPrimary = self::pages($this->primarySiteId())
            ->id(self::$entryIds['picks-primary-only'])->one()
            ->getFieldValue(self::FIELD_HANDLE);

        /** @var MenuBuilderFieldValue $onSecondary */
        $onSecondary = self::pages(self::$secondSiteId)
            ->id(self::$entryIds['picks-primary-only'])->one()
            ->getFieldValue(self::FIELD_HANDLE);

        $this->assertTrue($onPrimary->isAvailableForSite());
        $this->assertFalse(
            $onSecondary->isAvailableForSite(),
            'The menu is restricted to the primary site, and the value knows which site it came from.'
        );
    }

    /**
     * A restricted menu resolves to no tree on a site it isn't available on —
     * the group-level site gate in `MenuBuilderResolver::getTree()`, reached
     * through the field.
     */
    public function testARestrictedMenuResolvesToNoTreeOnASiteItIsNotAvailableOn(): void
    {
        $entry = self::pages($this->primarySiteId())->id(self::$entryIds['picks-primary-only'])->one();

        Craft::$app->getSites()->setCurrentSite($this->primarySiteId());
        $this->assertInstanceOf(
            MenuBuilderTree::class,
            $entry->getFieldValue(self::FIELD_HANDLE)->getTree(),
            'Available on the primary site.'
        );

        // A fresh read, because the value memoizes its tree by design.
        $again = self::pages($this->primarySiteId())->id(self::$entryIds['picks-primary-only'])->one();
        Craft::$app->getSites()->setCurrentSite(self::$secondSiteId);
        $this->assertNull(
            $again->getFieldValue(self::FIELD_HANDLE)->getTree(),
            'Not available on the second site — no tree at all, not an empty one.'
        );
    }

    /**
     * Site mismatch is a validation error on a translatable field, where the
     * author can pick a different menu for this site — and only there.
     */
    public function testSiteMismatchFailsValidationOnATranslatableField(): void
    {
        $entry = self::pages(self::$secondSiteId)->id(self::$entryIds['picks-primary-only'])->one();
        $entry->setScenario(Element::SCENARIO_LIVE);
        $entry->setFieldValue(self::PER_SITE_FIELD_HANDLE, (string)self::$primaryOnlyMenu->uid);

        $this->assertFalse($entry->validate(), 'A menu restricted away from this site is an error here.');
        $this->assertStringContainsString(
            'isn’t available on this site',
            implode(' ', $entry->getErrors(self::PER_SITE_FIELD_HANDLE)),
            'And it fails for the site reason specifically, not incidentally.'
        );
    }

    /**
     * The same selection on the same site is *not* an error on the shared
     * field: one value covers every site, so the restriction could never be
     * satisfied and the error would be unfixable.
     */
    public function testSiteMismatchIsNotAnErrorOnAnUntranslatableField(): void
    {
        $entry = self::pages(self::$secondSiteId)->id(self::$entryIds['picks-primary-only'])->one();
        $entry->setScenario(Element::SCENARIO_LIVE);
        $entry->setFieldValue(self::PER_SITE_FIELD_HANDLE, null);

        $entry->validate();

        $this->assertArrayNotHasKey(
            self::FIELD_HANDLE,
            $entry->getErrors(),
            'The shared field already holds the primary-only menu on this site, and that is allowed.'
        );
    }

    /** A deleted menu is an error on any site, translatable or not. */
    public function testADeletedMenuFailsValidationOnEverySite(): void
    {
        foreach ([$this->primarySiteId(), self::$secondSiteId] as $siteId) {
            $entry = self::pages($siteId)->id(self::$entryIds['picks-doomed'])->one();
            $entry->setScenario(Element::SCENARIO_LIVE);

            $this->assertFalse($entry->validate(), "Expected a validation failure on site $siteId.");
            $this->assertStringContainsString(
                'no longer exists',
                implode(' ', $entry->getErrors(self::FIELD_HANDLE)),
                "Expected the deleted-menu error on site $siteId."
            );
        }
    }

    // ---------------------------------------------------------------------
    // 3. The cross-site rendering limitation
    // ---------------------------------------------------------------------

    /**
     * **Regression test for a documented limitation, not for a bug.**
     *
     * `getTree()` resolves for the site of the *current request* — the site
     * whose element URLs, titles and cache entry a page is being built from —
     * and not for the site of the element the value came from. Those coincide
     * on every ordinary page. They diverge when a template renders an element
     * fetched from another site (`craft.entries.site('secondary')` inside a
     * primary-site request), and this test pins that divergence so a future
     * change to it is a deliberate decision rather than an accident.
     *
     * Resolving for an arbitrary site would mean plumbing a site ID through
     * the resolver, the link resolvers and the cache key — a change to the
     * resolve pipeline, which is explicitly out of scope for this phase. See
     * ARCHITECTURE.md "Known limitations".
     */
    public function testATreeResolvesForTheRequestsSiteNotTheElementsSite(): void
    {
        // A secondary-site element, read while the primary site is current.
        Craft::$app->getSites()->setCurrentSite($this->primarySiteId());

        $secondarySiteEntry = self::pages(self::$secondSiteId)
            ->id(self::$entryIds['picks-primary-only'])->one();

        $this->assertSame(self::$secondSiteId, $secondarySiteEntry->siteId);

        /** @var MenuBuilderFieldValue $value */
        $value = $secondarySiteEntry->getFieldValue(self::FIELD_HANDLE);

        $this->assertFalse(
            $value->isAvailableForSite(),
            'The value reports the element’s own site correctly…'
        );
        $this->assertInstanceOf(
            MenuBuilderTree::class,
            $value->getTree(),
            '…but the tree is resolved against the site being rendered, which here is the primary one. ' .
            'This is the documented cross-site limitation, pinned deliberately.'
        );
    }

    /**
     * The other half of the same limitation: the tree that comes back is the
     * *current* site's tree, so it is built from that site's resolver state.
     */
    public function testTheResolvedTreeBelongsToTheCurrentSite(): void
    {
        Craft::$app->getSites()->setCurrentSite(self::$secondSiteId);

        $entry = self::pages($this->primarySiteId())->id(self::$entryIds['picks-main'])->one();

        $this->assertSame($this->primarySiteId(), $entry->siteId);

        $tree = $entry->getFieldValue(self::FIELD_HANDLE)->getTree();

        $this->assertInstanceOf(MenuBuilderTree::class, $tree);
        $this->assertSame(
            'main',
            $tree->group->handle,
            'The menu is the one selected; the site it was resolved for is the current one.'
        );
    }
}

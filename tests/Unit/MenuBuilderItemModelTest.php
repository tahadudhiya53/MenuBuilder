<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderHierarchyHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * MenuBuilderItem validation — everything the model decides about its own
 * data before it reaches the database.
 *
 * Covers the per-type field rules, the per-item options (state, link
 * behaviour, appearance, accessibility, custom attributes), fail-closed shape
 * validation of the metadata bag (mega menu and dynamic source), and the URL
 * scheme denylist that keeps an executable scheme out of a rendered href.
 */
class MenuBuilderItemModelTest extends TestCase
{
    /** The simplest item that validates, as a starting point for each case. */
    private function urlItem(): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Products';
        $item->customUrl = '/products';

        return $item;
    }

    private function validUrlItem(array $visibility): MenuBuilderItem
    {
        $item = $this->urlItem();
        $item->visibility = $visibility;

        return $item;
    }

    // ---------------------------------------------------------------------
    // Per-type field rules
    // ---------------------------------------------------------------------

    public function testTitleRequiredUnlessSeparator(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = '';
        $item->customUrl = '/foo';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('title', $item->getErrors());

        $separator = new MenuBuilderItem();
        $separator->groupId = 1;
        $separator->type = MenuBuilderItem::TYPE_SEPARATOR;
        $separator->title = '';

        $this->assertTrue($separator->validate());
    }

    /**
     * An element-backed item may keep its title blank only when it
     * disappears with its element. The other two fallbacks keep the item on
     * the page after the element is gone, and a blank title has nothing left
     * to inherit from at that point.
     */
    public function testElementItemMayLeaveTitleBlankWhenItIsHiddenOnFallback(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_ENTRY;
        $item->elementId = 5;
        $item->title = '';
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_HIDE;

        $this->assertTrue($item->validate());
    }

    public function testElementItemThatSurvivesItsElementRequiresAnExplicitTitle(): void
    {
        foreach ([MenuBuilderItem::FALLBACK_DISABLE_LINK, MenuBuilderItem::FALLBACK_FALLBACK_URL] as $behavior) {
            $item = new MenuBuilderItem();
            $item->groupId = 1;
            $item->type = MenuBuilderItem::TYPE_ENTRY;
            $item->elementId = 5;
            $item->title = '';
            $item->fallbackBehavior = $behavior;
            $item->fallbackUrl = '/somewhere';

            $this->assertFalse($item->validate(), $behavior);
            $this->assertArrayHasKey('title', $item->getErrors(), $behavior);
        }
    }

    public function testElementItemWithATitlePassesForEveryFallbackBehavior(): void
    {
        foreach (MenuBuilderItem::FALLBACK_BEHAVIORS as $behavior) {
            $item = new MenuBuilderItem();
            $item->groupId = 1;
            $item->type = MenuBuilderItem::TYPE_ENTRY;
            $item->elementId = 5;
            $item->title = 'About';
            $item->fallbackBehavior = $behavior;
            $item->fallbackUrl = '/somewhere';

            $this->assertTrue($item->validate(), $behavior);
        }
    }

    public function testElementIdRequiredForElementTypes(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_ENTRY;
        $item->title = 'An entry link';
        $item->elementId = null;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('elementId', $item->getErrors());
    }

    public function testTitleOptionalForElementBackedTypes(): void
    {
        foreach ([MenuBuilderItem::TYPE_ENTRY, MenuBuilderItem::TYPE_CATEGORY, MenuBuilderItem::TYPE_ASSET] as $type) {
            $item = new MenuBuilderItem();
            $item->groupId = 1;
            $item->type = $type;
            $item->title = '';
            $item->elementId = 42;

            $this->assertTrue($item->validate(), "Expected blank title to be valid for type $type: " . json_encode($item->getErrors()));
        }
    }

    public function testCustomUrlValidation(): void
    {
        $valid = ['/relative/path', 'https://example.com', '#section', 'mailto:test@example.com', 'tel:+123456789', '/path?query=1#frag'];
        $invalid = ['', 'not a url', 'javascript:alert(1)'];

        foreach ($valid as $url) {
            $this->assertTrue(MenuBuilderItem::isPermissiveUrl($url), "Expected valid: $url");
        }

        foreach ($invalid as $url) {
            $this->assertFalse(MenuBuilderItem::isPermissiveUrl($url), "Expected invalid: $url");
        }
    }

    public function testFallbackUrlRequiredWhenFallbackBehaviorIsFallbackUrl(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = '/foo';
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_FALLBACK_URL;
        $item->fallbackUrl = null;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('fallbackUrl', $item->getErrors());
    }

    public function testCustomUrlRequiredForUrlType(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = null;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('customUrl', $item->getErrors());
    }

    public function testAnchorTargetRequired(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_ANCHOR;
        $item->title = 'Jump link';
        $item->handle = null;
        $item->customUrl = null;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('handle', $item->getErrors());

        $item->handle = 'section-2';
        $this->assertTrue($item->validate());
    }

    public function testAnchorTargetRejectsMalformedFragments(): void
    {
        foreach (['has space', "quo\"te", "sing'le", '<tag>', '#'] as $invalid) {
            $this->assertFalse(MenuBuilderItem::isValidAnchorTarget($invalid), "Expected invalid: $invalid");
        }

        foreach (['features', '#features', 'section-2', 'a.b:c'] as $valid) {
            $this->assertTrue(MenuBuilderItem::isValidAnchorTarget($valid), "Expected valid: $valid");
        }

        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_ANCHOR;
        $item->title = 'Jump link';
        $item->handle = 'has space';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('handle', $item->getErrors());
    }

    public function testHtmlAttributesRejectsEventHandlerKeys(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = '/foo';
        $item->htmlAttributes = ['onclick' => 'doSomething()'];

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('htmlAttributes', $item->getErrors());
    }

    public function testHtmlAttributesRejectsJavascriptUrls(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = '/foo';
        $item->htmlAttributes = ['data-href' => 'java script:alert(1)'];

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('htmlAttributes', $item->getErrors());
    }

    public function testHtmlAttributesAllowsSafeDataAttributes(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = '/foo';
        $item->htmlAttributes = ['data-tracking' => 'nav-main'];

        $this->assertTrue($item->validate());
    }

    public function testIsLinkable(): void
    {
        $entry = new MenuBuilderItem();
        $entry->type = MenuBuilderItem::TYPE_ENTRY;
        $this->assertTrue($entry->isLinkable());

        $heading = new MenuBuilderItem();
        $heading->type = MenuBuilderItem::TYPE_NONCLICKABLE;
        $this->assertFalse($heading->isLinkable());

        $separator = new MenuBuilderItem();
        $separator->type = MenuBuilderItem::TYPE_SEPARATOR;
        $this->assertFalse($separator->isLinkable());
    }

    /**
     * A non-clickable heading/separator must never become clickable just
     * because a customUrl or a stale clickable=true value is present on the
     * item — isLinkable() is authoritative regardless of those values.
     */
    public function testNonClickableAndSeparatorStayUnlinkableEvenWithCustomUrl(): void
    {
        $heading = new MenuBuilderItem();
        $heading->type = MenuBuilderItem::TYPE_NONCLICKABLE;
        $heading->customUrl = '/products';
        $heading->clickable = true;

        $this->assertFalse($heading->isLinkable());

        $separator = new MenuBuilderItem();
        $separator->type = MenuBuilderItem::TYPE_SEPARATOR;
        $separator->customUrl = '/products';
        $separator->clickable = true;

        $this->assertFalse($separator->isLinkable());
    }

    public function testSeparatorRequiresNoLinkConfiguration(): void
    {
        $separator = new MenuBuilderItem();
        $separator->groupId = 1;
        $separator->type = MenuBuilderItem::TYPE_SEPARATOR;

        $this->assertTrue($separator->validate(), 'A separator should validate with no title, URL, or element set: ' . json_encode($separator->getErrors()));
        $this->assertFalse($separator->isLinkable());
    }

    public function testVisibilityEmptyIsValid(): void
    {
        $this->assertTrue($this->validUrlItem([])->validate());
    }

    public function testVisibilityRejectsRuleWithoutType(): void
    {
        $item = $this->validUrlItem([['groupIds' => [1]]]);

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('visibility', $item->getErrors());
    }

    public function testVisibilityAllowsUnrecognizedTypeForThirdPartyRules(): void
    {
        // Types this model doesn't know the shape of (e.g. registered via
        // MenuBuilderVisibilityService::EVENT_REGISTER_VISIBILITY_RULES)
        // aren't rejected here — they're validated by their own rule class
        // at evaluation time instead.
        $item = $this->validUrlItem([['type' => 'thirdPartyRule', 'anything' => 'goes']]);

        $this->assertTrue($item->validate());
    }

    public function testVisibilityUserGroupRuleRequiresNumericIds(): void
    {
        $valid = $this->validUrlItem([['type' => 'userGroup', 'groupIds' => [1, 2]]]);
        $this->assertTrue($valid->validate(), json_encode($valid->getErrors()));

        $invalid = $this->validUrlItem([['type' => 'userGroup', 'groupIds' => ['not-an-id']]]);
        $this->assertFalse($invalid->validate());
        $this->assertArrayHasKey('visibility', $invalid->getErrors());
    }

    public function testVisibilitySiteRuleRequiresNumericIds(): void
    {
        $invalid = $this->validUrlItem([['type' => 'site', 'siteIds' => 'not-an-array']]);

        $this->assertFalse($invalid->validate());
        $this->assertArrayHasKey('visibility', $invalid->getErrors());
    }

    /** Bool/float must not slip through via a permissive `(string)` cast — `true` casts to `"1"`, which looks like a valid ID. */
    public function testVisibilityIdListRejectsInvalidPhpTypes(): void
    {
        foreach ([true, false, 1.5, null, ['nested']] as $badValue) {
            $item = $this->validUrlItem([['type' => 'userGroup', 'groupIds' => [$badValue]]]);

            $this->assertFalse($item->validate(), 'Expected invalid groupIds entry: ' . json_encode($badValue));
            $this->assertArrayHasKey('visibility', $item->getErrors());
        }
    }

    public function testVisibilityIdListRejectsZeroAndNegativeIds(): void
    {
        foreach ([[0], [-1], ['-1'], ['0']] as $badIds) {
            $item = $this->validUrlItem([['type' => 'site', 'siteIds' => $badIds]]);

            $this->assertFalse($item->validate(), 'Expected invalid siteIds: ' . json_encode($badIds));
        }
    }

    public function testVisibilityIdListAcceptsNumericStringIds(): void
    {
        $item = $this->validUrlItem([['type' => 'userGroup', 'groupIds' => ['1', '2']]]);

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
    }

    /**
     * An empty restriction list is rejected at save time rather than stored:
     * the rules fail closed on one (see UserGroupRule/SiteRule/
     * EnvironmentRule), so accepting it would persist an item that silently
     * never renders. Rejecting it keeps the model and the rules in agreement
     * and gives the editor or importer an actual error. "No restriction" is
     * the absence of the rule.
     */
    public function testVisibilityEmptyGroupIdsIsRejected(): void
    {
        $this->assertFalse($this->validUrlItem([['type' => 'userGroup', 'groupIds' => []]])->validate());
        $this->assertFalse($this->validUrlItem([['type' => 'userGroup']])->validate());
    }

    public function testVisibilityEmptySiteIdsIsRejected(): void
    {
        $this->assertFalse($this->validUrlItem([['type' => 'site', 'siteIds' => []]])->validate());
        $this->assertFalse($this->validUrlItem([['type' => 'site']])->validate());
    }

    public function testVisibilityEmptyEnvironmentsIsRejected(): void
    {
        $this->assertFalse($this->validUrlItem([['type' => 'environment', 'environments' => []]])->validate());
        $this->assertFalse($this->validUrlItem([['type' => 'environment']])->validate());
    }

    /**
     * The CP form only ever emits a rule when the editor actually picked
     * something (ItemsController::buildVisibilityRules), so a well-formed
     * restriction must still validate — this is the counterpart to the three
     * rejection tests above, guarding against over-tightening them.
     */
    public function testVisibilityWellFormedRestrictionsRemainValid(): void
    {
        foreach ([
            [['type' => 'userGroup', 'groupIds' => [2]]],
            [['type' => 'site', 'siteIds' => [1, 2]]],
            [['type' => 'environment', 'environments' => ['staging']]],
            [['type' => 'loggedIn'], ['type' => 'userGroup', 'groupIds' => [2]]],
            [['type' => 'always']],
        ] as $visibility) {
            $item = $this->validUrlItem($visibility);
            $this->assertTrue($item->validate(), json_encode($item->getErrors()));
        }
    }

    public function testVisibilityDateRangeRequiresAtLeastOneBound(): void
    {
        $item = $this->validUrlItem([['type' => 'dateRange']]);

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('visibility', $item->getErrors());
    }

    public function testVisibilityDateRangeRejectsInvalidDates(): void
    {
        $item = $this->validUrlItem([['type' => 'dateRange', 'start' => 'not-a-date']]);

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('visibility', $item->getErrors());
    }

    /** Malformed, non-string persisted values must fail closed rather than throw. */
    public function testVisibilityDateRangeRejectsNonStringDateValues(): void
    {
        foreach ([true, ['nested' => 'array'], 12345] as $badValue) {
            $item = $this->validUrlItem([['type' => 'dateRange', 'start' => $badValue]]);

            $this->assertFalse($item->validate(), 'Expected invalid start value: ' . json_encode($badValue));
            $this->assertArrayHasKey('visibility', $item->getErrors());
        }
    }

    /** DateTime would otherwise silently normalize this to March 2 instead of rejecting it. */
    public function testVisibilityDateRangeRejectsInvalidCalendarDates(): void
    {
        $item = $this->validUrlItem([['type' => 'dateRange', 'start' => '2026-02-30']]);

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('visibility', $item->getErrors());

        $item = $this->validUrlItem([['type' => 'dateRange', 'end' => '2026-04-31 10:00:00']]);

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('visibility', $item->getErrors());
    }

    public function testVisibilityDateRangeRejectsStartAfterEnd(): void
    {
        $item = $this->validUrlItem([['type' => 'dateRange', 'start' => '2026-09-30', 'end' => '2026-09-01']]);

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('visibility', $item->getErrors());
    }

    public function testVisibilityDateRangeAcceptsValidRange(): void
    {
        $item = $this->validUrlItem([['type' => 'dateRange', 'start' => '2026-09-01 09:00', 'end' => '2026-09-30 23:59']]);

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
    }

    public function testVisibilityEnvironmentRuleRequiresNonEmptyStrings(): void
    {
        $valid = $this->validUrlItem([['type' => 'environment', 'environments' => ['production', 'staging']]]);
        $this->assertTrue($valid->validate(), json_encode($valid->getErrors()));

        $invalid = $this->validUrlItem([['type' => 'environment', 'environments' => ['']]]);
        $this->assertFalse($invalid->validate());
    }

    public function testHasChildren(): void
    {
        $item = new MenuBuilderItem();
        $this->assertFalse($item->hasChildren());

        $item->children = [new MenuBuilderItem()];
        $this->assertTrue($item->hasChildren());
    }

    /**
     * Validation must inspect the same field the resolver renders (the
     * anchor field, `customUrl`, before the CSS-targeting `handle`) —
     * otherwise a malformed anchor rides along unvalidated behind a
     * perfectly valid handle.
     */
    public function testAMalformedAnchorFieldFailsValidationEvenWithAValidHandle(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_ANCHOR;
        $item->title = 'Jump link';
        $item->handle = 'valid-handle';
        $item->customUrl = 'has space';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('handle', $item->getErrors());
    }

    public function testAnchorFieldAloneIsEnough(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_ANCHOR;
        $item->title = 'Jump link';
        $item->handle = null;
        $item->customUrl = '#pricing';

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
    }

    // ---------------------------------------------------------------------
    // enabled / disabled: a disabled item takes its subtree with it
    // ---------------------------------------------------------------------

    public function testDisabledParentDoesNotPromoteItsChildrenToRoots(): void
    {
        // What MenuBuilderItemService::getTree() sees once the query has
        // dropped the disabled parent (id 2): the grandchild's parent chain
        // no longer reaches a root, so neither row belongs in the tree.
        $rows = [
            ['id' => 1, 'parentId' => null, 'sortOrder' => 1],
            ['id' => 3, 'parentId' => 2, 'sortOrder' => 2],
            ['id' => 4, 'parentId' => 3, 'sortOrder' => 3],
        ];

        $this->assertSame([1 => true], MenuBuilderHierarchyHelper::idsReachableFromRoots($rows));
    }

    public function testEnabledSubtreeIsFullyReachable(): void
    {
        $rows = [
            ['id' => 1, 'parentId' => null, 'sortOrder' => 1],
            ['id' => 2, 'parentId' => 1, 'sortOrder' => 2],
            ['id' => 3, 'parentId' => 2, 'sortOrder' => 3],
            ['id' => 4, 'parentId' => null, 'sortOrder' => 4],
        ];

        $reachable = MenuBuilderHierarchyHelper::idsReachableFromRoots($rows);

        $this->assertSame([1, 2, 3, 4], array_keys($reachable));
    }

    public function testDisabledLeafOnlyRemovesItself(): void
    {
        // Leaf id 3 disabled (absent); its parent and sibling are unaffected.
        $rows = [
            ['id' => 1, 'parentId' => null, 'sortOrder' => 1],
            ['id' => 2, 'parentId' => 1, 'sortOrder' => 2],
        ];

        $this->assertSame([1, 2], array_keys(MenuBuilderHierarchyHelper::idsReachableFromRoots($rows)));
    }

    public function testCyclicAncestryIsUnreachableRatherThanHanging(): void
    {
        $rows = [
            ['id' => 1, 'parentId' => 2, 'sortOrder' => 1],
            ['id' => 2, 'parentId' => 1, 'sortOrder' => 2],
            ['id' => 3, 'parentId' => null, 'sortOrder' => 3],
        ];

        $this->assertSame([3 => true], MenuBuilderHierarchyHelper::idsReachableFromRoots($rows));
    }

    public function testReachabilityHandlesRepeatedWalksOverTheSameChain(): void
    {
        // Memoisation path: 4 resolves through 3 → 2 → 1, all already known.
        $rows = [
            ['id' => 1, 'parentId' => null, 'sortOrder' => 1],
            ['id' => 2, 'parentId' => 1, 'sortOrder' => 2],
            ['id' => 3, 'parentId' => 2, 'sortOrder' => 3],
            ['id' => 4, 'parentId' => 3, 'sortOrder' => 4],
            ['id' => 5, 'parentId' => 99, 'sortOrder' => 5],
        ];

        $this->assertSame([1, 2, 3, 4], array_keys(MenuBuilderHierarchyHelper::idsReachableFromRoots($rows)));
    }

    // ---------------------------------------------------------------------
    // clickable
    // ---------------------------------------------------------------------

    public function testClickableRequiresLinkableTypeExplicitFlagAndUrl(): void
    {
        $this->assertTrue(LinkAttributeHelper::isClickable(true, true, '/products'));
        $this->assertFalse(LinkAttributeHelper::isClickable(false, true, '/products'), 'A structural type is never clickable.');
        $this->assertFalse(LinkAttributeHelper::isClickable(true, false, '/products'), 'clickable is explicit, never inferred from a URL.');
        $this->assertFalse(LinkAttributeHelper::isClickable(true, true, null));
    }

    public function testBlankUrlIsNotClickable(): void
    {
        $this->assertFalse(LinkAttributeHelper::isClickable(true, true, ''));
        $this->assertFalse(LinkAttributeHelper::isClickable(true, true, '   '));
    }

    public function testStructuralTypesAreNotLinkable(): void
    {
        $heading = $this->urlItem();
        $heading->type = MenuBuilderItem::TYPE_NONCLICKABLE;
        $this->assertFalse($heading->isLinkable());

        $separator = $this->urlItem();
        $separator->type = MenuBuilderItem::TYPE_SEPARATOR;
        $this->assertFalse($separator->isLinkable());

        $this->assertTrue($this->urlItem()->isLinkable());
    }

    // ---------------------------------------------------------------------
    // target / rel
    // ---------------------------------------------------------------------

    public function testTargetIsWhitelisted(): void
    {
        $item = $this->urlItem();
        $item->target = '_parent';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('target', $item->getErrors());
    }

    public function testNewTabAlwaysGetsNoopenerAndKeepsCustomRelValues(): void
    {
        $this->assertSame(
            'nofollow me ugc noopener',
            LinkAttributeHelper::mergeRelForTarget('_blank', 'nofollow me ugc')
        );
    }

    public function testNoopenerIsNotDuplicatedRegardlessOfCasing(): void
    {
        $this->assertSame('NOOPENER', LinkAttributeHelper::mergeRelForTarget('_blank', 'NOOPENER'));
    }

    public function testCombineRelDedupesTokensAcrossFields(): void
    {
        // The nofollow checkbox plus a hand-typed "nofollow noreferrer" in the
        // custom rel field — what ItemsController::buildRel() has to merge.
        $this->assertSame(
            'nofollow noreferrer',
            LinkAttributeHelper::combineRel(['nofollow', null, 'nofollow noreferrer'])
        );
    }

    public function testCombineRelIsNullWhenNothingIsSet(): void
    {
        $this->assertNull(LinkAttributeHelper::combineRel([null, '', '   ']));
    }

    public function testCombineRelKeepsFirstCasingAndOrder(): void
    {
        $this->assertSame('Sponsored nofollow', LinkAttributeHelper::combineRel(['Sponsored', 'nofollow SPONSORED']));
    }

    public function testRelLongerThanTheColumnIsRejected(): void
    {
        $item = $this->urlItem();
        $item->rel = str_repeat('a', 256);

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('rel', $item->getErrors());
    }

    // ---------------------------------------------------------------------
    // title / title fallback
    // ---------------------------------------------------------------------

    public function testExplicitTitleIsNeverOverwrittenByTheElementLabel(): void
    {
        $this->assertSame('Shop', LinkAttributeHelper::resolveTitle('Shop', 'Our Online Shop'));
        $this->assertSame('Our Online Shop', LinkAttributeHelper::resolveTitle('', 'Our Online Shop'));
    }

    // ---------------------------------------------------------------------
    // CSS class / HTML id / ARIA label / title attribute
    // ---------------------------------------------------------------------

    public function testHtmlIdRejectsWhitespaceAndQuoteCharacters(): void
    {
        foreach (['main nav', 'nav"x', "nav'x", 'nav<x>', ' '] as $value) {
            $this->assertFalse(LinkAttributeHelper::isValidHtmlId($value), "Expected \"$value\" to be rejected.");
        }

        $this->assertTrue(LinkAttributeHelper::isValidHtmlId('main-nav_1'));
    }

    public function testCssClassAllowsMultipleTokensButNotQuotesOrAngleBrackets(): void
    {
        $this->assertTrue(LinkAttributeHelper::isValidCssClassList('nav__item is-featured'));
        $this->assertFalse(LinkAttributeHelper::isValidCssClassList('nav" onclick="x'));
        $this->assertFalse(LinkAttributeHelper::isValidCssClassList('nav<script>'));
    }

    public function testItemRejectsAnUnsafeHtmlIdAndCssClass(): void
    {
        $item = $this->urlItem();
        $item->htmlId = 'nav" onclick="alert(1)';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('htmlId', $item->getErrors());

        $item = $this->urlItem();
        $item->cssClass = 'nav<script>';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('cssClass', $item->getErrors());
    }

    public function testAccessibilityAndAppearanceFieldsAcceptOrdinaryValues(): void
    {
        $item = $this->urlItem();
        $item->cssClass = 'nav__item nav__item--featured';
        $item->htmlId = 'nav-products';
        $item->ariaLabel = 'Products, opens in a new tab';
        $item->titleAttribute = 'All products';
        $item->icon = 'icon-cart';
        $item->badge = 'New';
        $item->description = str_repeat('A long description. ', 100);
        $item->image = 42;
        $item->featured = true;
        $item->target = '_blank';
        $item->rel = 'nofollow';

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
    }

    public function testAriaLabelAndTitleAttributeAreLengthCheckedAgainstTheirColumns(): void
    {
        foreach (['ariaLabel', 'titleAttribute', 'cssClass', 'htmlId', 'icon', 'badge'] as $attribute) {
            $item = $this->urlItem();
            $item->$attribute = str_repeat('a', 256);

            $this->assertFalse($item->validate(), "Expected an over-long $attribute to be rejected.");
            $this->assertArrayHasKey($attribute, $item->getErrors());
        }
    }

    // ---------------------------------------------------------------------
    // custom HTML attributes
    // ---------------------------------------------------------------------

    /**
     * @return array<string,array{array<string,string>}>
     */
    public static function unsafeAttributeProvider(): array
    {
        return [
            'onclick' => [['onclick' => 'alert(1)']],
            'onload' => [['onload' => 'alert(1)']],
            'onerror' => [['onerror' => 'alert(1)']],
            'mixed-case handler' => [['OnMouseOver' => 'alert(1)']],
            'arbitrary handler' => [['onpointerdown' => 'alert(1)']],
            'javascript scheme' => [['href' => 'javascript:alert(1)']],
            'javascript scheme, spaced' => [['href' => 'java script: alert(1)']],
            'javascript scheme, tab separated' => [['href' => "java\tscript:alert(1)"]],
            'javascript scheme, uppercase' => [['data-target' => 'JAVASCRIPT:alert(1)']],
            'vbscript scheme' => [['href' => 'vbscript:msgbox(1)']],
            'attribute-breaking name' => [['data-x" onclick="alert(1)' => 'y']],
            'empty name' => [['' => 'y']],
        ];
    }

    /**
     * @dataProvider unsafeAttributeProvider
     * @param array<string,string> $attributes
     */
    public function testUnsafeHtmlAttributesAreRejected(array $attributes): void
    {
        $this->assertNotSame([], LinkAttributeHelper::validateHtmlAttributes($attributes));

        $item = $this->urlItem();
        $item->htmlAttributes = $attributes;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('htmlAttributes', $item->getErrors());
    }

    public function testOrdinaryHtmlAttributesAreAccepted(): void
    {
        $attributes = [
            'data-tracking' => 'nav-main',
            'data-index' => '2',
            'aria-describedby' => 'nav-help',
            'xlink:href' => '#icon',
            // `data:` stays allowed: it has legitimate uses on a data-*
            // attribute, and a *link's* data: URL is refused separately by
            // MenuBuilderItem::isPermissiveUrl().
            'data-thumb' => 'data:image/png;base64,iVBORw0KGgo=',
        ];

        $this->assertSame([], LinkAttributeHelper::validateHtmlAttributes($attributes));

        $item = $this->urlItem();
        $item->htmlAttributes = $attributes;

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
    }

    public function testAttributeLinesParseIntoTheBagThatGetsValidated(): void
    {
        $parsed = LinkAttributeHelper::parseAttributeLines("data-tracking: nav-main\nonclick: alert(1)");

        $this->assertSame(['data-tracking' => 'nav-main', 'onclick' => 'alert(1)'], $parsed);
        $this->assertNotSame([], LinkAttributeHelper::validateHtmlAttributes($parsed));
    }

    // ---------------------------------------------------------------------
    // fallback behaviour
    // ---------------------------------------------------------------------

    public function testFallbackBehaviorIsWhitelisted(): void
    {
        $item = $this->urlItem();
        $item->fallbackBehavior = 'somethingElse';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('fallbackBehavior', $item->getErrors());
    }

    public function testFallbackUrlIsRequiredAndSchemeCheckedForThatBehavior(): void
    {
        $item = $this->urlItem();
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_FALLBACK_URL;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('fallbackUrl', $item->getErrors());

        $item = $this->urlItem();
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_FALLBACK_URL;
        $item->fallbackUrl = 'javascript:alert(1)';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('fallbackUrl', $item->getErrors());

        $item = $this->urlItem();
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_FALLBACK_URL;
        $item->fallbackUrl = '/products';

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
    }

    public function testDisableLinkFallbackNeedsNoFallbackUrl(): void
    {
        $item = $this->urlItem();
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_DISABLE_LINK;

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
    }

    // ---------------------------------------------------------------------
    // Metadata bag: mega menu and dynamic source
    // ---------------------------------------------------------------------

    public function testMegaMenuEnabledRequiresValidColumnsRange(): void
    {
        $item = $this->urlItem();
        $item->metadata = ['megaMenu' => ['enabled' => true, 'columns' => 0]];
        $this->assertFalse($item->validate());

        $item->metadata = ['megaMenu' => ['enabled' => true, 'columns' => 7]];
        $this->assertFalse($item->validate());

        $item->metadata = ['megaMenu' => ['enabled' => true, 'columns' => 3]];
        $this->assertTrue($item->validate());
    }

    public function testMegaMenuMustBeAnObject(): void
    {
        $item = $this->urlItem();
        $item->metadata = ['megaMenu' => 'not-an-array'];

        $this->assertFalse($item->validate());
    }

    public function testMegaMenuColumnMustBeInRange(): void
    {
        $item = $this->urlItem();
        $item->metadata = ['megaMenuColumn' => 0];
        $this->assertFalse($item->validate());

        $item->metadata = ['megaMenuColumn' => 7];
        $this->assertFalse($item->validate());

        $item->metadata = ['megaMenuColumn' => 2];
        $this->assertTrue($item->validate());
    }

    public function testDynamicTypeRequiresSourceConfig(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';

        $this->assertFalse($item->validate());
        $this->assertNotEmpty($item->getErrors('metadata'));
    }

    public function testDynamicSourceRejectsUnknownSourceType(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';
        $item->metadata = ['dynamicSource' => ['sourceType' => 'users', 'sourceId' => 1]];

        $this->assertFalse($item->validate());
    }

    public function testDynamicSourceRejectsInvalidSourceId(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';
        $item->metadata = ['dynamicSource' => ['sourceType' => 'entries', 'sourceId' => -1]];

        $this->assertFalse($item->validate());
    }

    public function testDynamicSourceRejectsUnknownOrderBy(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';
        $item->metadata = ['dynamicSource' => ['sourceType' => 'entries', 'sourceId' => 1, 'orderBy' => 'RAND()']];

        $this->assertFalse($item->validate());
    }

    public function testValidDynamicSourcePasses(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';
        $item->metadata = ['dynamicSource' => ['sourceType' => 'entries', 'sourceId' => 1, 'limit' => 10, 'orderBy' => 'title asc']];

        $this->assertTrue($item->validate());
    }

    public function testDynamicSourceLimitMustBePositiveInteger(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_DYNAMIC;
        $item->title = 'Latest news';
        $item->metadata = ['dynamicSource' => ['sourceType' => 'entries', 'sourceId' => 1, 'limit' => 0]];

        $this->assertFalse($item->validate());
    }

    // ---------------------------------------------------------------------
    // URL schemes that execute instead of navigating
    // ---------------------------------------------------------------------

    /**
     * @return array<string,array{string}>
     */
    public static function dangerousUrlProvider(): array
    {
        return [
            'bare javascript scheme' => ['javascript:alert(1)'],
            'javascript with authority and encoded newline' => ['javascript://example.test%0Aalert(1)'],
            'javascript with authority and literal newline' => ["javascript://example.test\nalert(1)"],
            'mixed case' => ['JaVaScRiPt://example.test%0Aalert(1)'],
            'leading whitespace' => ['   javascript://example.test%0Aalert(1)'],
            'whitespace inside the scheme' => ["java\tscript://example.test%0Aalert(1)"],
            'leading control character' => ["\x01javascript://example.test%0Aalert(1)"],
            'data scheme' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'data scheme with authority' => ['data://text/html,<script>alert(1)</script>'],
            'vbscript scheme' => ['vbscript://example.test%0Amsgbox(1)'],
        ];
    }

    /**
     * @dataProvider dangerousUrlProvider
     */
    public function testExecutableSchemesAreRejected(string $url): void
    {
        $this->assertFalse(MenuBuilderItem::isPermissiveUrl($url), "Expected rejected: $url");
    }

    /**
     * @dataProvider dangerousUrlProvider
     */
    public function testACustomUrlWithAnExecutableSchemeFailsValidation(string $url): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Tampered';
        $item->customUrl = $url;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('customUrl', $item->getErrors());
    }

    /**
     * The same denylist guards `fallbackUrl`, which becomes the rendered
     * href whenever a linked element is unavailable.
     */
    public function testAFallbackUrlWithAnExecutableSchemeFailsValidation(): void
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_ENTRY;
        $item->elementId = 5;
        $item->fallbackBehavior = MenuBuilderItem::FALLBACK_FALLBACK_URL;
        $item->fallbackUrl = 'javascript://example.test%0Aalert(1)';

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('fallbackUrl', $item->getErrors());
    }

    /**
     * The denylist matches the scheme, not the substring — a path or query
     * that merely contains one of these words is an ordinary URL.
     */
    public function testLegitimateUrlsThatMentionADeniedSchemeStillPass(): void
    {
        $safe = [
            'https://example.test/guides/javascript',
            '/downloads/data:sets',
            '#javascript',
            'https://example.test/?q=javascript:alert(1)',
            'mailto:someone@example.test',
            'tel:+123456789',
            '/relative/path',
        ];

        foreach ($safe as $url) {
            $this->assertTrue(MenuBuilderItem::isPermissiveUrl($url), "Expected accepted: $url");
        }
    }
}

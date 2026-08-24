<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

class MenuBuilderItemModelTest extends TestCase
{
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

    private function validUrlItem(array $visibility): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Link';
        $item->customUrl = '/foo';
        $item->visibility = $visibility;

        return $item;
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

    /** Empty/absent groupIds is a deliberate no-op (UserGroupRule passes unconditionally), not malformed config. */
    public function testVisibilityEmptyGroupIdsIsValidNoOp(): void
    {
        $this->assertTrue($this->validUrlItem([['type' => 'userGroup', 'groupIds' => []]])->validate());
        $this->assertTrue($this->validUrlItem([['type' => 'userGroup']])->validate());
    }

    /** Empty/absent siteIds is a deliberate no-op (SiteRule passes unconditionally), not malformed config. */
    public function testVisibilityEmptySiteIdsIsValidNoOp(): void
    {
        $this->assertTrue($this->validUrlItem([['type' => 'site', 'siteIds' => []]])->validate());
        $this->assertTrue($this->validUrlItem([['type' => 'site']])->validate());
    }

    /** Empty/absent environments is a deliberate no-op (EnvironmentRule passes unconditionally), not malformed config. */
    public function testVisibilityEmptyEnvironmentsIsValidNoOp(): void
    {
        $this->assertTrue($this->validUrlItem([['type' => 'environment', 'environments' => []]])->validate());
        $this->assertTrue($this->validUrlItem([['type' => 'environment']])->validate());
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
}

<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\helpers\ConfigHelper;
use Tahadudhiya\MenuBuilder\helpers\DateValidationHelper;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;

/**
 * The shared helpers that back several layers at once: the attribute-line
 * parser and link-attribute merging used by both controllers and the link
 * resolvers, the JSON bag decoder and ID-list normalizer used by the services
 * and GroupsController, and the calendar-date check used by MenuBuilderItem
 * and DateRangeRule.
 */
class MenuBuilderHelpersTest extends TestCase
{
    // ---------------------------------------------------------------------
    // LinkAttributeHelper: title fallback, rel merging, attribute safety
    // ---------------------------------------------------------------------

    public function testResolveTitlePrefersExplicitTitle(): void
    {
        $this->assertSame('About', LinkAttributeHelper::resolveTitle('About', 'About Our Company'));
    }

    public function testResolveTitleFallsBackToElementLabel(): void
    {
        $this->assertSame('About Our Company', LinkAttributeHelper::resolveTitle('', 'About Our Company'));
    }

    public function testResolveTitleEmptyWhenNeitherIsSet(): void
    {
        $this->assertSame('', LinkAttributeHelper::resolveTitle('', null));
    }

    public function testMergeRelForTargetLeavesSameWindowUntouched(): void
    {
        $this->assertNull(LinkAttributeHelper::mergeRelForTarget('_self', null));
        $this->assertSame('nofollow', LinkAttributeHelper::mergeRelForTarget('_self', 'nofollow'));
    }

    public function testMergeRelForTargetAddsNoopenerForNewTab(): void
    {
        $this->assertSame('noopener', LinkAttributeHelper::mergeRelForTarget('_blank', null));
    }

    public function testMergeRelForTargetMergesRatherThanOverwritesExistingRel(): void
    {
        $this->assertSame('nofollow sponsored noopener', LinkAttributeHelper::mergeRelForTarget('_blank', 'nofollow sponsored'));
    }

    public function testMergeRelForTargetDoesNotDuplicateNoopener(): void
    {
        $this->assertSame('noopener', LinkAttributeHelper::mergeRelForTarget('_blank', 'noopener'));
    }

    public function testMergeRelForTargetAddsNoopenerToSponsored(): void
    {
        $this->assertSame('sponsored noopener', LinkAttributeHelper::mergeRelForTarget('_blank', 'sponsored'));
    }

    public function testMergeRelForTargetDeduplicatesRepeatedTokens(): void
    {
        $this->assertSame('nofollow noopener', LinkAttributeHelper::mergeRelForTarget('_blank', 'nofollow noopener nofollow'));
    }

    public function testMergeRelForTargetKeepsSelfCustomRel(): void
    {
        $this->assertSame('custom-token', LinkAttributeHelper::mergeRelForTarget('_self', 'custom-token'));
    }

    public function testMergeRelForTargetDeduplicatesForSelfToo(): void
    {
        $this->assertSame('nofollow', LinkAttributeHelper::mergeRelForTarget('_self', 'nofollow nofollow'));
    }

    public function testValidateHtmlAttributesAcceptsWellFormedBag(): void
    {
        $this->assertSame([], LinkAttributeHelper::validateHtmlAttributes(['data-foo' => 'bar', 'aria-hidden' => 'true']));
    }

    public function testValidateHtmlAttributesRejectsInvalidKey(): void
    {
        $this->assertNotEmpty(LinkAttributeHelper::validateHtmlAttributes(['1invalid' => 'x']));
        $this->assertNotEmpty(LinkAttributeHelper::validateHtmlAttributes(['has space' => 'x']));
    }

    public function testValidateHtmlAttributesRejectsEventHandlerKeys(): void
    {
        $this->assertNotEmpty(LinkAttributeHelper::validateHtmlAttributes(['onclick' => 'alert(1)']));
        $this->assertNotEmpty(LinkAttributeHelper::validateHtmlAttributes(['onmouseover' => 'x']));
    }

    public function testValidateHtmlAttributesRejectsJavascriptUrls(): void
    {
        $this->assertNotEmpty(LinkAttributeHelper::validateHtmlAttributes(['data-href' => 'javascript:alert(1)']));
        $this->assertNotEmpty(LinkAttributeHelper::validateHtmlAttributes(['data-href' => 'java script:alert(1)']));
    }

    /**
     * rel tokens are case-insensitive to a browser, so an editor-typed
     * `NOOPENER` already satisfies the `_blank` requirement and a
     * differently-cased repeat is still a duplicate — casing of the first
     * occurrence is what gets emitted.
     */
    public function testMergeRelForTargetDeduplicatesCaseInsensitively(): void
    {
        $this->assertSame('NOOPENER', LinkAttributeHelper::mergeRelForTarget('_blank', 'NOOPENER'));
        $this->assertSame('Nofollow noopener', LinkAttributeHelper::mergeRelForTarget('_blank', 'Nofollow nofollow'));
        $this->assertSame('nofollow', LinkAttributeHelper::mergeRelForTarget('_self', 'nofollow NOFOLLOW'));
    }

    /** Extra whitespace between tokens never becomes an empty rel token. */
    public function testMergeRelForTargetIgnoresExtraWhitespace(): void
    {
        $this->assertSame('nofollow sponsored noopener', LinkAttributeHelper::mergeRelForTarget('_blank', "  nofollow \t sponsored  "));
        $this->assertNull(LinkAttributeHelper::mergeRelForTarget('_self', '   '));
    }

    // ---------------------------------------------------------------------
    // Attribute lines, JSON bags, ID lists, calendar dates
    // ---------------------------------------------------------------------

    public function testParseAttributeLinesBuildsKeyValueBag(): void
    {
        $parsed = LinkAttributeHelper::parseAttributeLines("data-role: primary\ndata-id: 7");

        $this->assertSame(['data-role' => 'primary', 'data-id' => '7'], $parsed);
    }

    public function testParseAttributeLinesSkipsLinesWithoutASeparatorAndBlankKeys(): void
    {
        $parsed = LinkAttributeHelper::parseAttributeLines("no-separator-here\n: orphaned\n\ndata-ok: yes");

        $this->assertSame(['data-ok' => 'yes'], $parsed);
    }

    /** A value may legitimately contain colons (a URL, a time) — only the first splits. */
    public function testParseAttributeLinesSplitsOnTheFirstColonOnly(): void
    {
        $parsed = LinkAttributeHelper::parseAttributeLines('data-src: https://example.test/a:b');

        $this->assertSame(['data-src' => 'https://example.test/a:b'], $parsed);
    }

    public function testDecodeJsonBagReturnsEmptyArrayForEveryUnusableValue(): void
    {
        $this->assertSame([], ConfigHelper::decodeJsonBag(null));
        $this->assertSame([], ConfigHelper::decodeJsonBag(''));
        $this->assertSame([], ConfigHelper::decodeJsonBag('{not json'));
        $this->assertSame([], ConfigHelper::decodeJsonBag('"a scalar"'));
    }

    public function testDecodeJsonBagDecodesAnObject(): void
    {
        $this->assertSame(['siteIds' => [1, 2]], ConfigHelper::decodeJsonBag('{"siteIds":[1,2]}'));
    }

    public function testNormalizeIdListDropsNonPositiveNonScalarAndDuplicateValues(): void
    {
        $this->assertSame([3, 5], ConfigHelper::normalizeIdList(['3', 0, 5, '3', -2, ['nested'], null]));
    }

    /**
     * Craft's checkbox-select posts a bare string rather than an array when
     * nothing is checked — that must read as "no restriction", not as one.
     */
    public function testNormalizeIdListTreatsANonArrayAsNoRestriction(): void
    {
        $this->assertSame([], ConfigHelper::normalizeIdList(''));
        $this->assertSame([], ConfigHelper::normalizeIdList('*'));
        $this->assertSame([], ConfigHelper::normalizeIdList(null));
    }

    public function testCalendarDateCheckRejectsImpossibleDatesAndAcceptsRealOnes(): void
    {
        $this->assertFalse(DateValidationHelper::hasValidCalendarDate('2026-02-30'));
        $this->assertFalse(DateValidationHelper::hasValidCalendarDate('2026-13-01T10:00'));
        $this->assertTrue(DateValidationHelper::hasValidCalendarDate('2024-02-29'));
        $this->assertTrue(DateValidationHelper::hasValidCalendarDate('2026-09-01T09:00+02:00'));
    }

    /** Only a leading Y-m-d is checked; anything else is left to DateTime. */
    public function testCalendarDateCheckPassesValuesWithoutALeadingDateComponent(): void
    {
        $this->assertTrue(DateValidationHelper::hasValidCalendarDate('now'));
        $this->assertTrue(DateValidationHelper::hasValidCalendarDate('tomorrow 09:00'));
    }
    /**
     * The strict list normalizers behind the visibility rules — separate
     * from normalizeIdList() on purpose: a rule that gates access must
     * reject junk (`null`, "fail closed") rather than quietly dropping it,
     * and must never intval a bool into a real group/site ID.
     */
    public function testStrictIdListAcceptsIntsAndDigitStrings(): void
    {
        $this->assertSame([1, 2, 3], ConfigHelper::strictIdList([1, '2', 3]));
        $this->assertSame([], ConfigHelper::strictIdList([]), 'An empty list is well-formed but empty — the caller decides what that means.');
        $this->assertSame([5], ConfigHelper::strictIdList([5, 5, '5']), 'Duplicates collapse.');
    }

    public function testStrictIdListReturnsNullForAnythingElse(): void
    {
        foreach ([null, 'x', 5, true, new \stdClass(), [true], [1.5], [null], ['5abc'], [''], [0], [-2], [['nested']], ['a' => 1]] as $value) {
            $this->assertNull(ConfigHelper::strictIdList($value), 'Malformed input must be rejected, not silently filtered: ' . var_export($value, true));
        }
    }

    public function testStrictIdListRejectsWhatNormalizeIdListWouldAccept(): void
    {
        $this->assertSame([1], ConfigHelper::normalizeIdList([true]), 'normalizeIdList is form-post oriented and intvals scalars.');
        $this->assertNull(ConfigHelper::strictIdList([true]), 'The access-gating counterpart must not turn a bool into ID 1.');
    }

    public function testStrictStringListTrimsAndDeduplicates(): void
    {
        $this->assertSame(['production', 'staging'], ConfigHelper::strictStringList([' production ', 'staging', 'production']));
        $this->assertSame([], ConfigHelper::strictStringList([]));
    }

    public function testStrictStringListReturnsNullForAnythingElse(): void
    {
        foreach ([null, 'production', 42, [''], ['  '], [42], [null], [['nested']], ['env' => 'production']] as $value) {
            $this->assertNull(ConfigHelper::strictStringList($value), 'Malformed input must be rejected: ' . var_export($value, true));
        }
    }
}

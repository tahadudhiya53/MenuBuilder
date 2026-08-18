<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;

class LinkAttributeHelperTest extends TestCase
{
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
}

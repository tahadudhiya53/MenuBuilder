<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;

class MenuBuilderGroupModelTest extends TestCase
{
    public function testNameAndHandleRequired(): void
    {
        $group = new MenuBuilderGroup();

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('name', $group->getErrors());
        $this->assertArrayHasKey('handle', $group->getErrors());
    }

    public function testHandleFormat(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = '1invalid';

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('handle', $group->getErrors());

        $group->handle = 'main_nav';
        $this->assertTrue($group->validate());
    }

    public function testAllowsDepth(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';

        // No limit configured.
        $this->assertTrue($group->allowsDepth(1));
        $this->assertTrue($group->allowsDepth(100));

        $group->maxDepth = 2;
        $this->assertTrue($group->allowsDepth(1));
        $this->assertTrue($group->allowsDepth(2));
        $this->assertFalse($group->allowsDepth(3));
    }

    public function testUsesDefineRulesNotRules(): void
    {
        // Craft 5 models must extend validation via defineRules(), not
        // override rules() directly (see MenuBuilderItem's equivalent
        // pattern) — a bare rules() override would silently bypass Model's
        // own attachBehaviors()/EVENT_DEFINE_RULES hook.
        $reflection = new \ReflectionMethod(MenuBuilderGroup::class, 'defineRules');
        $this->assertSame(MenuBuilderGroup::class, $reflection->getDeclaringClass()->getName());
    }

    public function testHtmlAttributesRejectsEventHandlerKeys(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';
        $group->htmlAttributes = ['onclick' => 'alert(1)'];

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('htmlAttributes', $group->getErrors());
    }

    public function testHtmlAttributesRejectsJavascriptUrls(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';
        $group->htmlAttributes = ['data-href' => 'javascript:alert(1)'];

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('htmlAttributes', $group->getErrors());
    }

    public function testHtmlAttributesAllowsWellFormedBag(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';
        $group->htmlAttributes = ['data-role' => 'nav'];

        $this->assertTrue($group->validate());
    }

    public function testSiteRestrictionDefaultsToEverySite(): void
    {
        $group = $this->validGroup();

        $this->assertTrue($group->validate());
        $this->assertTrue($group->isAvailableForSite(1));
        $this->assertTrue($group->isAvailableForSite(99));
        $this->assertTrue($group->isAvailableForSite(null), 'No restriction is available everywhere, console requests included.');
    }

    public function testSiteRestrictionLimitsAvailability(): void
    {
        $group = $this->validGroup();
        $group->siteIds = [1, 3];

        $this->assertTrue($group->validate());
        $this->assertTrue($group->isAvailableForSite(1));
        $this->assertTrue($group->isAvailableForSite(3));
        $this->assertFalse($group->isAvailableForSite(2));
        $this->assertFalse($group->isAvailableForSite(null), 'A restricted menu can’t be matched without a current site.');
    }

    /** @dataProvider malformedSiteIdsProvider */
    public function testSiteIdsRejectsMalformedLists(array $siteIds): void
    {
        $group = $this->validGroup();
        $group->siteIds = $siteIds;

        $this->assertFalse($group->validate(), 'Expected invalid siteIds: ' . json_encode($siteIds));
        $this->assertArrayHasKey('siteIds', $group->getErrors());
    }

    /** @return array<string,array{array<mixed>}> */
    public static function malformedSiteIdsProvider(): array
    {
        return [
            'string ids' => [['1', '2']],
            'zero' => [[0]],
            'negative' => [[-1]],
            'nested array' => [[[1]]],
        ];
    }

    private function validGroup(): MenuBuilderGroup
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';

        return $group;
    }
}

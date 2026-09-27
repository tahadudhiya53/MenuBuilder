<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderVisitUrlHelper;

/**
 * The "visit webpage" globe on a tree row: which destinations earn one, and what it opens.
 *
 * The rule the row depends on is that a globe is only ever a *page* — an editor who clicks one and
 * lands in a mail client, or on a fragment that resolves against the CP, has been told the link
 * works when nothing was checked.
*/
class MenuBuilderVisitUrlTest extends TestCase
{
    public function testAnAbsoluteHttpUrlIsVisitedAsItStands(): void
    {
        $this->assertSame('https://example.com/about', MenuBuilderVisitUrlHelper::absolute('https://example.com/about'));
        $this->assertSame('http://example.com/about', MenuBuilderVisitUrlHelper::absolute('http://example.com/about'));
    }

    public function testSurroundingWhitespaceDoesNotChangeTheDestination(): void
    {
        $this->assertSame('https://example.com/about', MenuBuilderVisitUrlHelper::absolute("  https://example.com/about\n"));
    }

    /**
     * A fragment is a position on whatever page embeds the menu, so there is no page to open —
     * and resolving it against the CP would send the editor to a CP URL with a `#` on the end.
    */
    public function testAFragmentHasNoPageToVisit(): void
    {
        $this->assertNull(MenuBuilderVisitUrlHelper::absolute('#contact'));
    }

    /**
     * mailto:/tel: are valid menu links and deliberately globe-less: opening a mail client is not
     * a preview of anything, and neither is a scheme that executes instead of navigating.
    */
    public function testNonNavigatingSchemesGetNoGlobe(): void
    {
        $this->assertNull(MenuBuilderVisitUrlHelper::absolute('mailto:hello@example.com'));
        $this->assertNull(MenuBuilderVisitUrlHelper::absolute('tel:+441234567890'));
        $this->assertNull(MenuBuilderVisitUrlHelper::absolute('javascript:alert(1)'));
        $this->assertNull(MenuBuilderVisitUrlHelper::absolute('ftp://example.com/file.zip'));
    }

    public function testAnEmptyDestinationIsNotAPage(): void
    {
        $this->assertNull(MenuBuilderVisitUrlHelper::absolute(''));
        $this->assertNull(MenuBuilderVisitUrlHelper::absolute('   '));
    }

    /**
     * Only the four types that address a page are considered at all; an anchor, a heading, a
     * separator and a dynamic container are excluded before any URL is resolved.
    */
    public function testOnlyPageAddressingTypesAreConsidered(): void
    {
        $types = new \ReflectionClassConstant(MenuBuilderVisitUrlHelper::class, 'VISITABLE_TYPES');

        $this->assertSame(['entry', 'category', 'asset', 'url'], $types->getValue());
    }

    /**
     * The row's globe is Craft's own: same icon, same new tab, same `rel` — and an accessible name
     * that says which item it belongs to, since a column of identical "Visit webpage" links tells
     * a screen reader user nothing.
    */
    public function testTheRowRendersCraftsGlobeAffordance(): void
    {
        $row = (string)file_get_contents(__DIR__ . '/../../src/templates/dashboard/_items.twig');

        $this->assertStringContainsString('data-icon="world"', $row);
        $this->assertStringContainsString('target="_blank"', $row);
        $this->assertStringContainsString('rel="noopener"', $row);
        $this->assertStringContainsString("'Visit {title}'|t('menubuilder', { title: itemLabel })", $row);
        // Absent from the map means "no page", not "fall back to something".
        $this->assertStringContainsString('itemUrls is defined and itemUrls[item.id] is defined', $row);
    }

    /** The globe's URLs travel with the rest of the row's context, not as a separate include. */
    public function testTheUrlMapIsPartOfTheSharedTreeContext(): void
    {
        $index = (string)file_get_contents(__DIR__ . '/../../src/templates/dashboard/index.twig');
        $controller = (string)file_get_contents(__DIR__ . '/../../src/controllers/DashboardController.php');

        $this->assertStringContainsString('itemUrls: itemUrls,', $index);
        $this->assertStringContainsString("'itemUrls' => MenuBuilderVisitUrlHelper::forItems(\$flat),", $controller);
    }
}

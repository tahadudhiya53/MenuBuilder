<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/NavMacroRendering.php';

/**
 * What `_macros/breadcrumbs.twig` actually emits, asserted against the DOM a
 * browser receives — the breadcrumb counterpart to
 * {@see MenuBuilderAccessibilityTest}, sharing the same harness so both
 * macros are tested against the same definition of "the markup the front end
 * gets".
 *
 * The trail itself (which nodes, in which order) is
 * {@see MenuBuilderBreadcrumbTest}'s subject; this file only asks what the
 * markup promises assistive technology about them.
 */
class MenuBuilderBreadcrumbRenderTest extends TestCase
{
    use NavMacroRendering;

    /** Products › Shoes › Running Shoes, the last being the page being served. */
    private function trailNodes(): array
    {
        return [
            $this->node(3, title: 'Products', url: '/products', isActiveAncestor: true),
            $this->node(4, title: 'Shoes', url: '/products/shoes', level: 2, isActiveAncestor: true),
            $this->node(5, title: 'Running Shoes', url: '/products/shoes/running', level: 3, isActive: true),
        ];
    }

    public function testTrailIsANamedNavigationLandmark(): void
    {
        $html = $this->renderBreadcrumbs($this->trailNodes());

        $nav = $this->query($html, '//nav');

        $this->assertCount(1, $nav);
        $this->assertSame('Breadcrumb', $nav[0]->getAttribute('aria-label'));
    }

    public function testLabelCanBeOverridden(): void
    {
        $html = $this->renderBreadcrumbs($this->trailNodes(), 'You are here');

        $this->assertSame('You are here', $this->query($html, '//nav')[0]->getAttribute('aria-label'));
    }

    /** An ordered list, because the order of a trail is its meaning. */
    public function testTrailIsAnOrderedList(): void
    {
        $html = $this->renderBreadcrumbs($this->trailNodes());

        $this->assertCount(1, $this->query($html, '//nav/ol'));
        $this->assertCount(0, $this->query($html, '//nav//ul'));
        $this->assertSame(
            ['Products', 'Shoes', 'Running Shoes'],
            array_map(fn($li) => trim($li->textContent), $this->query($html, '//nav/ol/li'))
        );
    }

    /** `aria-current="page"` identifies the page being served — the last crumb, and nothing else. */
    public function testOnlyTheLastCrumbIsMarkedAsTheCurrentPage(): void
    {
        $html = $this->renderBreadcrumbs($this->trailNodes());

        $current = $this->query($html, '//*[@aria-current]');

        $this->assertCount(1, $current);
        $this->assertSame('page', $current[0]->getAttribute('aria-current'));
        $this->assertSame('Running Shoes', trim($current[0]->textContent));
    }

    /**
     * With `linkCurrent = false` the last crumb is plain text — still the
     * current page, because that is `aria-current`'s job and not the anchor's.
     */
    public function testCurrentCrumbCanBeRenderedWithoutALink(): void
    {
        $html = $this->renderBreadcrumbs($this->trailNodes(), linkCurrent: false);

        $this->assertCount(2, $this->query($html, '//ol/li/a'));

        $current = $this->query($html, '//*[@aria-current="page"]');
        $this->assertSame('span', $current[0]->tagName);
        $this->assertSame('Running Shoes', trim($current[0]->textContent));
    }

    /** A non-clickable crumb is a label, never a fake link — the same rule the menu itself obeys. */
    public function testNonClickableCrumbRendersNoAnchor(): void
    {
        $html = $this->renderBreadcrumbs([
            $this->node(3, title: 'Products', url: null, isClickable: false, isActiveAncestor: true),
            $this->node(4, title: 'Shoes', url: '/products/shoes', level: 2, isActive: true),
        ]);

        $links = $this->query($html, '//ol/li/a');

        $this->assertCount(1, $links);
        $this->assertSame('Shoes', trim($links[0]->textContent));
        $this->assertSame('Products', trim($this->query($html, '//ol/li[1]/span')[0]->textContent));
    }

    /** An empty trail renders nothing at all — never an empty landmark to go and discover. */
    public function testEmptyTrailRendersNothing(): void
    {
        $this->assertSame('', trim($this->renderBreadcrumbs([])));
    }

    /** A missing/disabled menu (`craft.menuBuilder.breadcrumbs()` returned null) renders nothing either. */
    public function testMissingMenuRendersNothing(): void
    {
        $this->assertSame('', trim($this->renderBreadcrumbsForMissingMenu()));
    }

    /** No separator characters in the markup: a screen reader would read every one of them out. */
    public function testNoTextSeparatorsBetweenCrumbs(): void
    {
        $html = $this->renderBreadcrumbs($this->trailNodes());

        foreach (['›', '»', '/', '>', '|'] as $separator) {
            $this->assertStringNotContainsString(
                $separator,
                trim($this->query($html, '//ol')[0]->textContent),
                "Separator \"$separator\" belongs to CSS, not to the markup"
            );
        }
    }

    /** A crumb that opens a new tab says so, using the tree macros' one definition of that phrase. */
    public function testNewTabCrumbAnnouncesItself(): void
    {
        $html = $this->renderBreadcrumbs([
            $this->node(3, title: 'Docs', url: 'https://docs.example.test', target: '_blank', rel: 'noopener', isActiveAncestor: true),
            $this->node(4, title: 'Guide', url: '/guide', level: 2, isActive: true),
        ]);

        $link = $this->query($html, '//ol/li[1]/a')[0];

        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertStringContainsString('(opens in a new tab)', $link->textContent);
    }

    /** Editor-supplied attributes go through the same filter the menu uses. */
    public function testUnsafeCustomAttributesAreDropped(): void
    {
        $html = $this->renderBreadcrumbs([
            $this->node(3, title: 'Products', url: '/products', htmlAttributes: ['onclick' => 'alert(1)', 'data-test' => 'ok'], isActiveAncestor: true),
            $this->node(4, title: 'Shoes', url: '/products/shoes', level: 2, isActive: true),
        ]);

        $link = $this->query($html, '//ol/li[1]/a')[0];

        $this->assertFalse($link->hasAttribute('onclick'));
        $this->assertSame('ok', $link->getAttribute('data-test'));
    }

    /** The item's own CSS class reaches its crumb, alongside the macro's own hooks. */
    public function testCrumbCarriesItemAndStateClasses(): void
    {
        $html = $this->renderBreadcrumbs([
            $this->node(3, title: 'Products', url: '/products', cssClass: 'is-promo', isActiveAncestor: true),
            $this->node(4, title: 'Shoes', url: '/products/shoes', level: 2, isActive: true),
        ]);

        $items = $this->query($html, '//ol/li');

        $this->assertStringContainsString('is-promo', $items[0]->getAttribute('class'));
        $this->assertStringNotContainsString('is-current', $items[0]->getAttribute('class'));
        $this->assertStringContainsString('is-current', $items[1]->getAttribute('class'));
    }
}

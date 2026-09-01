<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;

require_once __DIR__ . '/NavMacroRendering.php';

/**
 * What a browser actually receives for each viewport — the bundled macros
 * rendered through real Twig against real nodes, asserted against the DOM.
 *
 * The two rendering strategies MenuBuilder supports are both tested here,
 * because they are the whole of the front-end contract:
 *
 * 1. **One navigation, `data-mb-viewport`.** The default. Every item renders
 *    once; the ones an editor restricted carry one attribute, and the
 *    theme's own media query decides at what width each is `display:none`.
 *    No breakpoint, media query or framework class is emitted by this
 *    plugin — asserted, not assumed.
 * 2. **Two navigations, `forViewport()`.** The tree is narrowed and re-sorted
 *    server-side and rendered twice, with `idPrefix` keeping the two copies'
 *    HTML ids apart. This is the only strategy in which mobile *ordering*
 *    means anything, because order has to be DOM order (WCAG 1.3.2, 2.4.3).
 *
 * Accessibility is not a separate section below: it is the point of most of
 * these assertions. A collapsed branch is a native `<details>`, so "what the
 * markup says" and "what is on screen" are one attribute the browser owns;
 * there is no `aria-expanded` anywhere to fall out of step, and nothing in
 * the mobile navigation is reachable by pointer but not by keyboard.
 */
class MenuBuilderMobileRenderTest extends TestCase
{
    use NavMacroRendering;

    /** Home, a desktop-only download, a mobile-only phone link, and a branch. */
    private function mixedMenu(): array
    {
        return [
            $this->node(1, title: 'Home', url: '/'),
            $this->node(2, title: 'Print catalogue', url: '/catalogue.pdf', mobile: ['visibility' => 'desktopOnly']),
            $this->node(3, title: 'Call us', url: 'tel:+441234', mobile: ['visibility' => 'mobileOnly']),
            $this->node(4, title: 'Explore', url: '/explore', children: [
                $this->node(5, title: 'Latest', url: '/latest', level: 2),
                $this->node(6, title: 'Archive', url: '/archive', level: 2),
            ]),
        ];
    }

    /** @return string[] */
    private function linkTexts(string $html): array
    {
        return array_map(static fn($node) => trim($node->textContent), $this->query($html, '//a'));
    }

    // ---------------------------------------------------------------
    // Strategy 1: one navigation, data-mb-viewport
    // ---------------------------------------------------------------

    public function testASharedNavigationRendersEveryItemOnceAndMarksTheRestrictedOnes(): void
    {
        $html = $this->renderNav($this->mixedMenu());

        $this->assertCount(6, $this->query($html, '//a'), 'Every item renders exactly once — the same link is never in the page twice.');
        $this->assertCount(1, $this->query($html, '//li[@data-mb-viewport="desktop"]'));
        $this->assertCount(1, $this->query($html, '//li[@data-mb-viewport="mobile"]'));
    }

    /** The attribute says something only when the editor restricted the item. */
    public function testAnUnrestrictedItemCarriesNoViewportAttribute(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Home', url: '/')]);

        $this->assertCount(0, $this->query($html, '//li[@data-mb-viewport]'));
    }

    public function testARestrictedSeparatorIsMarkedToo(): void
    {
        $html = $this->renderNav([$this->node(1, type: 'separator', mobile: ['visibility' => 'desktopOnly'])]);

        $this->assertCount(1, $this->query($html, '//li[@data-mb-viewport="desktop"]/hr'));
    }

    /** A restricted child is marked wherever it sits, not only at the top level. */
    public function testARestrictedNestedItemIsMarked(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Explore', url: '/explore', children: [
                $this->node(2, title: 'Call us', url: 'tel:+441234', level: 2, mobile: ['visibility' => 'mobileOnly']),
            ]),
        ]);

        $this->assertCount(1, $this->query($html, '//li//ul/li[@data-mb-viewport="mobile"]'));
    }

    /**
     * MenuBuilder decides *which* items belong to a viewport; where a
     * viewport begins is the front-end developer's decision alone. If this
     * ever fails, the plugin has started owning a breakpoint.
     */
    public function testTheMacrosEmitNoBreakpointMediaQueryOrFrameworkClass(): void
    {
        $html = $this->renderNav($this->mixedMenu(), 'details', 'mobile')
            . $this->renderNav($this->mixedMenu(), 'details', 'both');

        $this->assertStringNotContainsString('@media', $html);
        $this->assertStringNotContainsString('px', $html);
        $this->assertStringNotContainsString('rem', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(sm|md|lg|xl):/', $html, 'No utility-framework responsive prefixes.');
        $this->assertStringNotContainsString('<style', $html);
    }

    // ---------------------------------------------------------------
    // Strategy 2: two navigations via forViewport()
    // ---------------------------------------------------------------

    public function testTheDesktopNavigationDropsMobileOnlyItems(): void
    {
        $html = $this->renderNavForViewport($this->mixedMenu(), 'desktop');
        $links = $this->linkTexts($html);

        $this->assertContains('Print catalogue', $links);
        $this->assertNotContains('Call us', $links, 'A mobile-only item is absent from the desktop DOM entirely.');
    }

    public function testTheMobileNavigationDropsDesktopOnlyItems(): void
    {
        $html = $this->renderNavForViewport($this->mixedMenu(), 'mobile');
        $links = $this->linkTexts($html);

        $this->assertContains('Call us', $links);
        $this->assertNotContains('Print catalogue', $links);
    }

    /** A narrowed tree needs no attribute: everything left in it belongs. */
    public function testANarrowedNavigationEmitsNoPerItemViewportAttributes(): void
    {
        foreach (['desktop', 'mobile'] as $viewport) {
            $html = $this->renderNavForViewport($this->mixedMenu(), $viewport);

            $this->assertCount(0, $this->query($html, '//li[@data-mb-viewport]'));
            $this->assertCount(1, $this->query($html, '//nav[@data-mb-viewport="' . $viewport . '"]'), 'The landmark says which navigation it is, so CSS has one hook for the whole thing.');
        }
    }

    /** Two navigations on one page must not produce two elements with the same id. */
    public function testTheTwoNavigationsDoNotCollideOnHtmlIds(): void
    {
        $nodes = [$this->node(1, title: 'Home', url: '/', htmlId: 'home')];
        $html = $this->renderNavForViewport($nodes, 'desktop') . $this->renderNavForViewport($nodes, 'mobile');

        $ids = array_map(static fn($node) => $node->getAttribute('id'), $this->query($html, '//li[@id]'));

        $this->assertSame(['desktop-home', 'mobile-home'], $ids);
        $this->assertSame($ids, array_unique($ids));
    }

    /** Order has to be DOM order, or the visual and focus sequences disagree. */
    public function testMobileOrderIsAppliedByReorderingTheDomAndNeverByCss(): void
    {
        $nodes = [
            $this->node(1, title: 'Home', url: '/'),
            $this->node(2, title: 'Products', url: '/products'),
            $this->node(3, title: 'Contact', url: '/contact', mobile: ['order' => 1]),
        ];

        $html = $this->renderNavForViewport($nodes, 'mobile');

        $this->assertSame(['Contact', 'Home', 'Products'], $this->linkTexts($html));
        $this->assertStringNotContainsString('order:', $html, 'A CSS `order` would break WCAG 1.3.2 / 2.4.3 — see MobileHelper.');
        $this->assertStringNotContainsString('style=', str_replace('style="position:absolute', '', $html));
    }

    public function testTheDesktopNavigationKeepsTheEditorsOrder(): void
    {
        $nodes = [
            $this->node(1, title: 'Home', url: '/'),
            $this->node(2, title: 'Contact', url: '/contact', mobile: ['order' => 1]),
        ];

        $this->assertSame(['Home', 'Contact'], $this->linkTexts($this->renderNavForViewport($nodes, 'desktop')));
    }

    // ---------------------------------------------------------------
    // Collapsible children
    // ---------------------------------------------------------------

    private function branch(array $mobile = []): MenuBuilderNode
    {
        return $this->node(1, title: 'Explore', url: '/explore', mobile: $mobile, children: [
            $this->node(2, title: 'Latest', url: '/latest', level: 2),
            $this->node(3, title: 'Archive', url: '/archive', level: 2),
        ]);
    }

    public function testAMobileBranchIsANativeDetailsDisclosure(): void
    {
        $html = $this->renderNav([$this->branch()], 'details', 'mobile');
        $details = $this->query($html, '//details[@data-mb-submenu]');

        $this->assertCount(1, $details);
        $this->assertFalse($details[0]->hasAttribute('open'), 'A branch starts collapsed, so a deep menu fits a phone.');
        $this->assertCount(1, $this->query($html, '//details[@data-mb-submenu]/summary'));
        $this->assertCount(1, $this->query($html, '//details[@data-mb-submenu]/ul'));
    }

    /** The state is one attribute the browser owns — there is no second copy of it to drift. */
    public function testACollapsedBranchClaimsNoAriaStateOfItsOwn(): void
    {
        $html = $this->renderNav([$this->branch()], 'details', 'mobile');

        $this->assertStringNotContainsString('aria-expanded', $html);
        $this->assertStringNotContainsString('aria-haspopup', $html);
        $this->assertStringNotContainsString('role="menu"', $html);
        $this->assertStringNotContainsString('role="button"', $html);
        $this->assertStringNotContainsString('tabindex', $html);
    }

    /**
     * The parent's own label is already a link above the summary, so the
     * summary must not repeat it — two controls with the same accessible
     * name, one of which navigates and one of which opens.
     */
    public function testTheSummaryIsNamedForWhatItDoesRatherThanRepeatingTheLabel(): void
    {
        $summary = $this->query($this->renderNav([$this->branch()], 'details', 'mobile'), '//summary');

        $this->assertSame('Explore submenu', $summary[0]->getAttribute('aria-label'));
        $this->assertCount(1, $summary[0]->getElementsByTagName('span'), 'The caret is the summary\'s only content.');
        $this->assertCount(1, $this->query($this->renderNav([$this->branch()], 'details', 'mobile'), '//summary/span[@aria-hidden="true"]'), 'And it is decorative, so the aria-label is the whole accessible name.');
    }

    public function testAnAriaLabelledParentNamesItsSummaryFromThatLabel(): void
    {
        $node = $this->node(1, title: 'Explore', url: '/explore', ariaLabel: 'Explore our work', children: [
            $this->node(2, title: 'Latest', url: '/latest', level: 2),
        ]);

        $summary = $this->query($this->renderNav([$node], 'details', 'mobile'), '//summary');

        $this->assertSame('Explore our work submenu', $summary[0]->getAttribute('aria-label'));
    }

    /** A collapsed branch's links stay in the DOM, so find-in-page and a screen reader's own expansion still reach them. */
    public function testACollapsedBranchStillContainsItsLinks(): void
    {
        $html = $this->renderNav([$this->branch()], 'details', 'mobile');

        $this->assertSame(['Explore', 'Latest', 'Archive'], $this->linkTexts($html));
    }

    public function testAnEditorCanKeepABranchExpandedOnMobile(): void
    {
        $html = $this->renderNav([$this->branch(['collapsible' => false])], 'details', 'mobile');

        $this->assertCount(0, $this->query($html, '//details'));
        $this->assertCount(1, $this->query($html, '//li/ul'), 'The children render in flow, as they do on desktop.');
        $this->assertSame(['Explore', 'Latest', 'Archive'], $this->linkTexts($html));
    }

    /** A leaf has nothing to disclose, so it never becomes a control that opens an empty panel. */
    public function testALeafIsNeverWrappedInADisclosure(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Home', url: '/')], 'details', 'mobile');

        $this->assertCount(0, $this->query($html, '//details'));
        $this->assertCount(0, $this->query($html, '//summary'));
    }

    /** Nothing changes on desktop: a submenu is still a nested list with no state claimed. */
    public function testTheDesktopNavigationStillRendersPlainNestedLists(): void
    {
        foreach (['desktop', 'both'] as $viewport) {
            $html = $this->renderNav([$this->branch()], 'details', $viewport);

            $this->assertCount(0, $this->query($html, '//details'), 'Collapsing is a mobile behaviour, not a new desktop one.');
            $this->assertCount(1, $this->query($html, '//li/ul'));
        }
    }

    /** `disclosure: 'none'` means "claim no state" — that promise holds on mobile too. */
    public function testDisclosureNoneClaimsNoStateOnMobileEither(): void
    {
        $html = $this->renderNav([$this->branch()], 'none', 'mobile');

        $this->assertCount(0, $this->query($html, '//details'));
        $this->assertSame(['Explore', 'Latest', 'Archive'], $this->linkTexts($html));
    }

    public function testNestedBranchesEachGetTheirOwnDisclosure(): void
    {
        $node = $this->node(1, title: 'Explore', url: '/explore', children: [
            $this->node(2, title: 'Blog', url: '/blog', level: 2, children: [
                $this->node(3, title: 'Latest', url: '/latest', level: 3),
            ]),
        ]);

        $html = $this->renderNav([$node], 'details', 'mobile');

        $this->assertCount(2, $this->query($html, '//details[@data-mb-submenu]'));
        $this->assertCount(1, $this->query($html, '//details//details[@data-mb-submenu]'), 'A branch inside a branch nests rather than flattening.');
    }

    // ---------------------------------------------------------------
    // Mega menus on mobile
    // ---------------------------------------------------------------

    public function testAMegaMenuStacksIntoOneListOnMobileByDefault(): void
    {
        $html = $this->renderNav([$this->megaParent()], 'details', 'mobile');

        $this->assertCount(0, $this->query($html, '//div[contains(@class, "menu-builder-megamenu-column")]'), 'Columns are not a thing a 390px screen can show.');
        $this->assertCount(1, $this->query($html, '//details[@data-mb-submenu]'));
        $this->assertSame(['Explore', 'Latest posts', 'Older posts', 'Jump to'], $this->linkTexts($html), 'Stacking loses no link and keeps DOM order.');
    }

    public function testAMegaMenuCanKeepItsColumnsOnMobile(): void
    {
        $node = $this->megaParentWithMobile(['megaMenu' => 'columns']);
        $html = $this->renderNav([$node], 'details', 'mobile');

        $this->assertCount(2, $this->query($html, '//div[contains(@class, "menu-builder-megamenu-column")]'));
        $this->assertCount(1, $this->query($html, '//details[@data-mb-submenu]'), 'It is still one drawer disclosure, not a hover flyout.');
    }

    /** A sharp, deliberate choice: those links then exist in no navigation a phone has. */
    public function testAMegaMenuCanBeHiddenEntirelyOnMobile(): void
    {
        $html = $this->renderNav([$this->megaParentWithMobile(['megaMenu' => 'hide'])], 'details', 'mobile');

        $this->assertSame(['Explore'], $this->linkTexts($html), 'Only the parent is left.');
        $this->assertCount(0, $this->query($html, '//details'));
        $this->assertCount(0, $this->query($html, '//li/ul'));
    }

    public function testTheDesktopMegaMenuIsUnchangedByAnyMobileBehavior(): void
    {
        foreach (['stack', 'columns', 'hide'] as $behavior) {
            $html = $this->renderNav([$this->megaParentWithMobile(['megaMenu' => $behavior])], 'details', 'desktop');

            $this->assertCount(1, $this->query($html, '//details[@data-mb-disclosure]'), $behavior);
            $this->assertCount(2, $this->query($html, '//div[contains(@class, "menu-builder-megamenu-column")]'), $behavior);
        }
    }

    /** The mega parent's panel is still named and grouped when its columns are kept. */
    public function testAKeptColumnPanelStillCarriesItsGroupLabel(): void
    {
        $html = $this->renderNav([$this->megaParentWithMobile(['megaMenu' => 'columns'])], 'details', 'mobile');
        $panel = $this->query($html, '//div[@role="group"]');

        $this->assertCount(1, $panel);
        $this->assertSame('Explore', $panel[0]->getAttribute('aria-label'));
    }

    // ---------------------------------------------------------------
    // Active state
    // ---------------------------------------------------------------

    /** `aria-current="page"` must survive `forViewport()`, or the mobile navigation stops saying where you are. */
    public function testTheActiveItemStaysMarkedInBothNavigations(): void
    {
        $nodes = [
            $this->node(1, title: 'Home', url: '/'),
            $this->node(2, title: 'Latest', url: '/latest', isActive: true, mobile: ['order' => 1]),
        ];

        foreach (['desktop', 'mobile'] as $viewport) {
            $html = $this->renderNavForViewport($nodes, $viewport);
            $current = $this->query($html, '//a[@aria-current="page"]');

            $this->assertCount(1, $current, $viewport);
            $this->assertSame('Latest', trim($current[0]->textContent), $viewport);
        }
    }

    /** The ancestor of the current page keeps its styling hook and still claims no aria-current of its own. */
    public function testAnActiveAncestorKeepsItsClassInTheMobileNavigation(): void
    {
        $child = $this->node(2, title: 'Latest', url: '/latest', level: 2, isActive: true);
        $parent = $this->node(1, title: 'Explore', url: '/explore', isActiveAncestor: true, children: [$child]);

        $html = $this->renderNavForViewport([$parent], 'mobile');

        $this->assertCount(1, $this->query($html, '//li[contains(@class, "is-active")]/details'));
        $this->assertCount(1, $this->query($html, '//a[@aria-current="page"]'), 'Only the page being served is the current page.');
    }

    // ---------------------------------------------------------------
    // Keyboard navigation
    // ---------------------------------------------------------------

    /**
     * Everything the mobile navigation can do by pointer, it can do by
     * keyboard — because `<details>`/`<summary>` is the whole mechanism.
     * A summary is focusable and toggled by Enter and Space natively, with
     * no script, no `tabindex`, and no key handler to get wrong.
     */
    public function testEveryMobileControlIsANativelyFocusableElement(): void
    {
        $html = $this->renderNav([$this->branch(), $this->megaParent()], 'details', 'mobile');

        foreach ($this->query($html, '//summary') as $summary) {
            $this->assertFalse($summary->hasAttribute('tabindex'), 'A summary is already focusable; a tabindex would only be a way to break it.');
        }

        $this->assertCount(0, $this->query($html, '//*[@onclick or @onkeydown or @onkeyup]'), 'No inline handlers: interaction is the element, not a script.');
        $this->assertCount(0, $this->query($html, '//div[@role="button"] | //span[@role="button"]'), 'No fake buttons.');
        $this->assertGreaterThan(0, count($this->query($html, '//summary')));
    }

    
    /** Nothing in the mobile navigation is hidden from the keyboard while remaining on screen. */
    public function testNoMobileItemIsHiddenFromTheKeyboardOnly(): void
    {
        $html = $this->renderNav($this->mixedMenu(), 'details', 'mobile');

        $this->assertCount(0, $this->query($html, '//*[@aria-hidden="true"]//a'), 'A link inside an aria-hidden subtree is reachable by Tab and invisible to a screen reader.');
        $this->assertCount(0, $this->query($html, '//a[@aria-hidden="true"]'));
    }

    /** A mega parent with two columns, plus a mobile config on the parent. */
    private function megaParentWithMobile(array $mobile): MenuBuilderNode
    {
        return $this->node(1, title: 'Explore', url: '/explore', megaMenu: new MenuBuilderMegaMenuConfig(columns: 2), mobile: $mobile, children: [
            $this->node(2, title: 'Latest posts', url: '/blog/latest', level: 2, megaMenuColumn: 1),
            $this->node(3, title: 'Older posts', url: '/blog/archive', level: 2, megaMenuColumn: 1),
            $this->node(4, title: 'Jump to', url: '/jump', level: 2, megaMenuColumn: 2),
        ]);
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use Tahadudhiya\MenuBuilder\web\assets\nav\NavAsset;

require_once __DIR__ . '/NavMacroRendering.php';

/**
 * What the rendered navigation promises assistive technology.
 *
 * Every assertion here runs against markup produced by the plugin's real
 * macros (see {@see NavMacroRendering}), because an accessibility guarantee
 * is a property of what the browser receives, not of what the template
 * source looks like.
 *
 * The rule this file exists to hold: **an attribute must describe something
 * that is true.** `aria-current="page"` on one link only, because only one
 * link can be the page being served; `aria-expanded` only where something
 * expands and something keeps the attribute in step; no `aria-haspopup` on a
 * panel of ordinary links, because that announces a menu widget with keyboard
 * behaviour these links do not have. An attribute that overstates is worse
 * than none: it sends a screen-reader user looking for a control that isn't
 * there.
 *
 * The other half is that editor-authored data cannot forge any of it. A
 * custom-attributes bag is the one editor value whose *keys* reach markup
 * where an attribute name goes, so it is re-checked at render time and
 * filtered — see {@see LinkAttributeHelper::filterHtmlAttributes()}.
 */
class MenuBuilderAccessibilityTest extends TestCase
{
    use NavMacroRendering;

    // ---------------------------------------------------------------------
    // The navigation landmark
    // ---------------------------------------------------------------------

    /** A menu is a landmark, named after itself, so a page with two of them is navigable. */
    public function testTheNavigationIsALandmarkNamedAfterTheMenu(): void
    {
        $html = $this->renderNavLandmark([$this->node(1, title: 'Home', url: '/')]);

        $nav = $this->query($html, '//nav');

        $this->assertCount(1, $nav);
        $this->assertSame('Main', $nav[0]->getAttribute('aria-label'));
        $this->assertCount(1, $this->query($html, '//nav/ul/li/a[@href="/"]'));
    }

    /** A template can name the landmark itself — "Utility links", not a second "Main". */
    public function testAnExplicitLabelWinsOverTheMenuName(): void
    {
        $html = $this->renderNavLandmark([$this->node(1, title: 'Home', url: '/')], label: 'Utility links');

        $this->assertSame('Utility links', $this->query($html, '//nav')[0]->getAttribute('aria-label'));
    }

    /**
     * An empty menu renders nothing at all. An empty landmark is something a
     * screen-reader user has to navigate to in order to discover it was
     * empty.
     */
    public function testAnEmptyMenuRendersNoLandmarkAtAll(): void
    {
        $html = $this->renderNavLandmark([]);

        $this->assertSame('', trim($html));
    }

    /**
     * The menu's own attributes reach the landmark, but the ones that would
     * unname it, un-label it or take its role away do not: a `role="banner"`
     * typed into a menu's attributes bag would stop it being a navigation.
     */
    public function testTheMenusOwnAttributesReachTheLandmarkButCannotRedefineIt(): void
    {
        $html = $this->renderNavLandmark([$this->node(1, title: 'Home', url: '/')], [
            'cssClass' => 'site-nav',
            'htmlAttributes' => [
                'data-analytics' => 'main-nav',
                'role' => 'banner',
                'onclick' => 'alert(1)',
                'id' => 'stolen',
            ],
        ]);

        $nav = $this->query($html, '//nav')[0];

        $this->assertSame('site-nav', $nav->getAttribute('class'));
        $this->assertSame('main-nav', $nav->getAttribute('data-analytics'));
        $this->assertSame('', $nav->getAttribute('role'), 'A menu cannot stop being a navigation landmark.');
        $this->assertSame('', $nav->getAttribute('onclick'));
        $this->assertSame('', $nav->getAttribute('id'));
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    // ---------------------------------------------------------------------
    // aria-current
    // ---------------------------------------------------------------------

    /**
     * `aria-current="page"` is the answer to "am I on this page", and only
     * one link in a tree can be. An ancestor of the active item is styled as
     * an open branch and says nothing about being the current page.
     */
    public function testAriaCurrentPageLandsOnTheActiveLinkAndNowhereElse(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Products', url: '/products', isActiveAncestor: true, children: [
                $this->node(2, title: 'Shoes', url: '/products/shoes', level: 2, isActive: true),
                $this->node(3, title: 'Boots', url: '/products/boots', level: 2),
            ]),
            $this->node(4, title: 'Contact', url: '/contact'),
        ]);

        $current = $this->query($html, '//*[@aria-current]');

        $this->assertCount(1, $current, 'Exactly one element in the whole navigation is the current page.');
        $this->assertSame('a', $current[0]->tagName);
        $this->assertSame('page', $current[0]->getAttribute('aria-current'));
        $this->assertSame('/products/shoes', $current[0]->getAttribute('href'));

        $ancestor = $this->query($html, '//a[@href="/products"]')[0];
        $ancestorItem = $this->query($html, '//li[a/@href="/products"]')[0];

        $this->assertSame('', $ancestor->getAttribute('aria-current'));
        $this->assertStringContainsString('is-active', $ancestorItem->getAttribute('class'), 'An open branch is styled by class, not announced by ARIA.');
    }

    /** Nothing is the current page when nothing matches the request. */
    public function testNoLinkIsMarkedCurrentWhenNothingIsActive(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Home', url: '/'),
            $this->node(2, title: 'News', url: '/news'),
        ]);

        $this->assertCount(0, $this->query($html, '//*[@aria-current]'));
    }

    /**
     * A heading is not a page, so it never claims to be the current one —
     * even if the resolver somehow marked it active. `aria-current` lives on
     * the `<a>` branch of the macro alone.
     */
    public function testANonClickableHeadingNeverCarriesAriaCurrent(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Explore', url: null, type: 'nonclickable', isClickable: false, isActive: true),
        ]);

        $this->assertCount(0, $this->query($html, '//*[@aria-current]'));
        $this->assertCount(1, $this->query($html, '//li/span'));
    }

    /** An editor cannot type a second current page into an item's attributes bag. */
    public function testACustomAttributeCannotForgeAriaCurrent(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Home', url: '/', htmlAttributes: ['aria-current' => 'page']),
            $this->node(2, title: 'News', url: '/news', isActive: true),
        ]);

        $current = $this->query($html, '//*[@aria-current]');

        $this->assertCount(1, $current);
        $this->assertSame('/news', $current[0]->getAttribute('href'), 'The active item is the only current page.');
    }

    // ---------------------------------------------------------------------
    // Links, headings and keyboard reachability
    // ---------------------------------------------------------------------

    /**
     * A heading is a `<span>`: no `href`, no `role="link"`, no `tabindex`.
     * A focusable element that does nothing when activated is a dead stop on
     * the Tab path.
     */
    public function testAHeadingIsNotFocusableAndNotAFakeLink(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Explore', url: null, type: 'nonclickable', isClickable: false, htmlAttributes: ['tabindex' => '0', 'role' => 'link']),
        ]);

        $span = $this->query($html, '//li/span')[0];

        $this->assertSame('', $span->getAttribute('tabindex'));
        $this->assertSame('', $span->getAttribute('role'));
        $this->assertSame('', $span->getAttribute('href'));
        $this->assertCount(0, $this->query($html, '//a'));
    }

    /**
     * Nothing in the macro takes a link out of the keyboard's path or
     * reorders it: no `tabindex` is emitted anywhere, and one an editor
     * types is dropped.
     */
    public function testNoLinkIsGivenATabindex(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Home', url: '/', htmlAttributes: ['tabindex' => '5']),
            $this->node(2, title: 'Skip me', url: '/skip', htmlAttributes: ['tabindex' => '-1']),
            $this->megaParent(),
        ]);

        $this->assertCount(0, $this->query($html, '//*[@tabindex]'));
    }

    /** A visible link that is hidden from a screen reader is a link half the audience can't use. */
    public function testAVisibleLinkCannotBeHiddenFromAssistiveTechnology(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Home', url: '/', htmlAttributes: ['aria-hidden' => 'true'])]);

        $this->assertSame('', $this->query($html, '//a')[0]->getAttribute('aria-hidden'));
    }

    // ---------------------------------------------------------------------
    // Opening in a new tab
    // ---------------------------------------------------------------------

    /**
     * A new tab is a change of context a sighted user reads from the
     * browser. It has to be in the link's accessible name for everyone else
     * (WCAG 3.2.5) — and hidden by the macro itself, so it doesn't become
     * visible text in a theme with no visually-hidden helper.
     */
    public function testALinkThatOpensANewTabSaysSoInItsAccessibleName(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Partner', url: 'https://elsewhere.test', target: '_blank', rel: 'noopener'),
        ]);

        $link = $this->query($html, '//a')[0];

        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertStringContainsString('Partner', $link->textContent);
        $this->assertStringContainsString('(opens in a new tab)', $link->textContent);

        $hint = $this->query($html, '//a/span[contains(@class, "menu-builder-visually-hidden")]')[0];

        $this->assertStringContainsString('clip-path:inset(50%)', $hint->getAttribute('style'), 'The hint is hidden without needing the theme’s CSS.');
    }

    /** A same-tab link says nothing, and doesn't carry the default `target` either. */
    public function testASameTabLinkCarriesNoTargetAndNoHint(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Home', url: '/')]);

        $this->assertSame('', $this->query($html, '//a')[0]->getAttribute('target'));
        $this->assertStringNotContainsString('opens in a new tab', $html);
    }

    /** A heading opens nothing, whatever `target` its row happens to carry. */
    public function testAHeadingNeverAnnouncesANewTab(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Explore', url: null, type: 'nonclickable', isClickable: false, target: '_blank'),
        ]);

        $this->assertStringNotContainsString('opens in a new tab', $html);
    }

    // ---------------------------------------------------------------------
    // Mega menus: one state, owned by the browser
    // ---------------------------------------------------------------------

    /**
     * **The regression this section exists for.** A disclosure has two things
     * that must never disagree: whether the panel is on screen, and what the
     * markup says about it. Splitting those across an `aria-expanded`
     * attribute (owned by whatever script is running) and a CSS rule (owned
     * by the theme) is what produced a panel opened on `:hover` while the
     * button still said `aria-expanded="false"` — and no amount of scripting
     * from inside the plugin could guarantee otherwise.
     *
     * `<details>` removes the second state rather than trying to synchronise
     * it: `open` *is* the rendering and *is* the accessible state. So the
     * check is structural — there is no disclosure attribute in this markup
     * that could be out of step with anything, and the panel is *inside* the
     * element that owns the state, not a sibling a stylesheet can reveal on
     * its own.
     */
    public function testAMegaMenuHasNoDisclosureStateThatCouldContradictWhatIsOnScreen(): void
    {
        $html = $this->renderNav([$this->megaParent()]);

        $this->assertCount(0, $this->query($html, '//*[@aria-expanded]'), 'No second opinion about whether the panel is open.');
        $this->assertCount(0, $this->query($html, '//*[@aria-haspopup]'));
        $this->assertCount(0, $this->query($html, '//*[@aria-controls]'), 'Containment states the relationship; an attribute would only repeat it.');

        $details = $this->query($html, '//details')[0];

        $this->assertFalse($details->hasAttribute('open'), 'A menu arrives closed.');
        $this->assertCount(
            1,
            $this->query($html, '//details/div[contains(@class, "menu-builder-megamenu-panel")]'),
            'The panel lives inside the element that owns the state, so "closed" is not something CSS can contradict.'
        );
        $this->assertCount(0, $this->query($html, '//li/div[contains(@class, "menu-builder-megamenu-panel")]'), 'It is never a sibling of the summary’s <details>.');
    }

    /**
     * The native disclosure structure, which is what makes the browser's
     * guarantee apply: `<summary>` first, panel after it, both inside one
     * `<details>`.
     */
    public function testAMegaMenuIsANativeDisclosure(): void
    {
        $html = $this->renderNav([$this->megaParent()]);

        $details = $this->query($html, '//li/details[@data-mb-disclosure]');

        $this->assertCount(1, $details);
        $this->assertSame('summary', $details[0]->firstElementChild->tagName, 'A summary that is not the first child is not a disclosure control.');

        $panel = $this->query($html, '//details/div[contains(@class, "menu-builder-megamenu-panel")]')[0];

        $this->assertSame('group', $panel->getAttribute('role'));
        $this->assertSame('Explore', $panel->getAttribute('aria-label'));
        $this->assertSame('menu-builder-megamenu-' . 1, $panel->getAttribute('id'), 'The panel keeps a stable id for themes to target.');
    }

    /**
     * The item's label is already on screen, as the link or heading beside
     * the disclosure. Repeating it would put two controls with the same name
     * next to each other, with nothing to say which one opens the panel — so
     * the summary's name says what it does, over a decorative caret.
     */
    public function testTheSummaryNamesWhatItDoesInsteadOfRepeatingTheLabel(): void
    {
        $html = $this->renderNav([$this->megaParent()]);

        $summary = $this->query($html, '//details/summary')[0];

        $this->assertSame('Explore submenu', $summary->getAttribute('aria-label'));

        $caret = $this->query($html, '//summary/span[contains(@class, "menu-builder-megamenu-caret")]')[0];

        $this->assertSame('true', $caret->getAttribute('aria-hidden'), 'The caret is an affordance, not a word.');
        $this->assertSame('▾', trim($summary->textContent), 'The summary carries no label text of its own.');
        $this->assertSame('Explore', trim($this->query($html, '//li/a')[0]->textContent), 'The item still renders its own label, once.');
    }

    /** An ARIA label set on the item is what the summary and the panel are named after. */
    public function testTheSummaryUsesTheItemsAriaLabelWhenItHasOne(): void
    {
        $node = $this->megaParent();
        $labelled = $this->node(
            1,
            title: 'Explore',
            url: '/explore',
            ariaLabel: 'Explore our products',
            megaMenu: $node->megaMenu,
            children: $node->children,
        );

        $html = $this->renderNav([$labelled]);

        $this->assertSame('Explore our products submenu', $this->query($html, '//summary')[0]->getAttribute('aria-label'));
        $this->assertSame('Explore our products', $this->query($html, '//div[contains(@class, "menu-builder-megamenu-panel")]')[0]->getAttribute('aria-label'));
    }

    /** The panel's contents are links, not menu items: no roles that change what keys mean. */
    public function testAMegaMenuPanelContainsOrdinaryLinks(): void
    {
        $html = $this->renderNav([$this->megaParent()]);

        $links = $this->query($html, '//div[contains(@class, "menu-builder-megamenu-panel")]//a');

        $this->assertCount(3, $links);

        foreach ($links as $link) {
            $this->assertSame('', $link->getAttribute('role'));
            $this->assertSame('', $link->getAttribute('tabindex'));
        }

        $this->assertCount(0, $this->query($html, '//*[@role="menu" or @role="menuitem" or @role="menubar"]'));
    }

    /**
     * A mega menu inside a mega-menu column is just another `<details>` —
     * each one owns its own state, and neither claims anything about the
     * other. Nesting is where a hand-maintained `aria-expanded` goes wrong
     * first.
     */
    public function testANestedMegaMenuIsItsOwnIndependentDisclosure(): void
    {
        $inner = $this->node(5, title: 'Guides', url: '/guides', level: 2, megaMenuColumn: 1, megaMenu: new MenuBuilderMegaMenuConfig(columns: 1), children: [
            $this->node(6, title: 'Getting started', url: '/guides/start', level: 3),
        ]);
        $outer = $this->node(1, title: 'Explore', url: '/explore', megaMenu: new MenuBuilderMegaMenuConfig(columns: 1), children: [$inner]);

        $html = $this->renderNav([$outer]);

        $this->assertCount(2, $this->query($html, '//details'));
        $this->assertCount(1, $this->query($html, '//details//details'), 'The inner disclosure sits inside the outer one.');
        $this->assertCount(0, $this->query($html, '//*[@aria-expanded]'));

        foreach ($this->query($html, '//details') as $details) {
            $this->assertFalse($details->hasAttribute('open'));
            $this->assertSame('summary', $details->firstElementChild->tagName);
        }
    }

    /**
     * The escape hatch for a theme that opens its panels its own way: no
     * `<details>`, no summary, and — the point — no state claimed on the
     * plugin's behalf that the theme would then have to keep true.
     */
    public function testTheNoDisclosureModeClaimsNoStateAtAll(): void
    {
        $html = $this->renderNav([$this->megaParent()], disclosure: 'none');

        $this->assertCount(0, $this->query($html, '//details'));
        $this->assertCount(0, $this->query($html, '//summary'));
        $this->assertCount(0, $this->query($html, '//*[@aria-expanded]'));
        $this->assertCount(0, $this->query($html, '//*[@aria-haspopup]'));

        $panel = $this->query($html, '//li/div[contains(@class, "menu-builder-megamenu-panel")]');

        $this->assertCount(1, $panel, 'The columns still render, in flow, as a named group.');
        $this->assertSame('group', $panel[0]->getAttribute('role'));
        $this->assertCount(3, $this->query($html, '//div[contains(@class, "menu-builder-megamenu-panel")]//a'), 'Every link is still reachable.');
    }

    /** The mode reaches every level of the tree, not only the node it was called on. */
    public function testTheDisclosureModeIsThreadedThroughTheWholeTree(): void
    {
        $nested = $this->node(1, title: 'Explore', url: '/explore', children: [
            $this->megaParent(),
        ]);

        $this->assertCount(1, $this->query($this->renderNav([$nested]), '//details'));
        $this->assertCount(0, $this->query($this->renderNav([$nested], disclosure: 'none'), '//details'));
        $this->assertCount(0, $this->query($this->renderNavLandmark([$nested], disclosure: 'none'), '//details'), 'Including through the landmark macro.');
    }

    /**
     * The bundled script is an enhancement over the native element, never
     * the thing that makes the markup honest — so what is checked here is
     * that it owns no state of its own. It sets `details.open`, which *is*
     * the state, and writes no `aria-expanded`/`data-*` copy of it that
     * could drift.
     *
     * Its keyboard behaviour (Escape, arrows, Home/End) is browser
     * behaviour and is verified manually — see ACCESSIBILITY.md. There is no
     * JavaScript test runner in this plugin, and adding npm + a DOM
     * implementation to a Craft plugin that ships five hand-written scripts
     * would be a build system bought for one file; what that would buy is
     * coverage of *keys*, not of the guarantee, which is exactly the part
     * `<details>` moved into the browser.
     */
    public function testTheBundledScriptOwnsNoStateOfItsOwn(): void
    {
        $bundle = (string)file_get_contents(__DIR__ . '/../../src/web/assets/nav/NavAsset.php');

        // Comments stripped first: this is about what the script *does*, and
        // its docblock necessarily talks about the attribute it refuses to
        // write.
        $script = (string)preg_replace(
            ['~/\*.*?\*/~s', '~^\s*//.*$~m'],
            '',
            (string)file_get_contents(__DIR__ . '/../../src/web/assets/nav/js/menu-builder-nav.js')
        );

        $this->assertStringContainsString("\$this->js = ['js/menu-builder-nav.js'];", $bundle);
        $this->assertTrue(class_exists(NavAsset::class));

        $this->assertStringNotContainsString('aria-expanded', $script, 'There is no attribute copy of the state to keep in step.');
        $this->assertStringNotContainsString('data-mb-open', $script);
        $this->assertStringNotContainsString('setAttribute', $script, 'The script writes no state onto the markup at all.');
        $this->assertStringContainsString('details.open', $script, 'It opens and closes by the native property.');
    }

    /**
     * The one rule a stylesheet has to obey, checked against the only
     * stylesheet this plugin ships: **nothing may make a mega-menu panel
     * visible while its `<details>` is closed.**
     *
     * This is where the bug actually lived. The control panel's preview
     * opened mega panels with `li:hover > … > .menu-builder-megamenu-panel {
     * display: grid }` while the disclosure state was maintained separately —
     * so the plugin's own screen demonstrated a panel that was open to the
     * eye and closed to a screen reader. A DOM test can't catch that, because
     * the divergence lives in CSS; so the CSS is read the way a browser
     * reads it, and every rule that gives the panel a box has to be scoped to
     * `details[open]`.
     */
    public function testNoStylesheetRuleRevealsAPanelWhoseDisclosureIsClosed(): void
    {
        $css = (string)file_get_contents(__DIR__ . '/../../src/web/assets/cp/menu-builder-cp.css');
        $css = (string)preg_replace('~/\*.*?\*/~s', '', $css);

        preg_match_all('~([^{}]+)\{([^{}]*)\}~s', $css, $rules, PREG_SET_ORDER);

        $checked = 0;

        foreach ($rules as [, $selectorList, $declarations]) {
            if (!preg_match('~(^|[^-])display\s*:\s*([a-z-]+)~i', $declarations, $display) || strtolower($display[2]) === 'none') {
                continue;
            }

            foreach (explode(',', $selectorList) as $selector) {
                $selector = trim($selector);
                $parts = preg_split('#[\s>+~]+#', $selector) ?: [$selector];
                $subject = (string)end($parts);

                if (!str_contains($subject, 'menu-builder-megamenu-panel')) {
                    continue;
                }

                $checked++;

                $this->assertStringContainsString(
                    'details[open]',
                    $selector,
                    "\"$selector\" gives a mega-menu panel a box without requiring its <details> to be open."
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'Expected the preview stylesheet to style an open panel at all.');
    }

    // ---------------------------------------------------------------------
    // Custom attributes cannot inject behaviour
    // ---------------------------------------------------------------------

    /**
     * The bag reaches markup where an *attribute name* goes, which is the
     * one editor-authored position Twig's escaping does not make safe. It is
     * re-checked at render time, so a row that never passed save-time
     * validation — an import, a direct database write, a row older than the
     * rule — still can't emit a handler.
     *
     * @dataProvider dangerousAttributeProvider
     */
    public function testDangerousCustomAttributesNeverReachTheMarkup(array $attributes, string $needle): void
    {
        foreach ([
            $this->node(1, title: 'Home', url: '/', htmlAttributes: $attributes),
            $this->node(2, title: 'Explore', url: null, type: 'nonclickable', isClickable: false, htmlAttributes: $attributes),
        ] as $node) {
            $html = $this->renderNav([$node]);
            $element = $this->query($html, '//li/*')[0];

            $this->assertStringNotContainsStringIgnoringCase($needle, $html);

            foreach ($attributes as $name => $value) {
                $this->assertSame('', $element->getAttribute((string)$name), "\"$name\" must not reach the markup.");
            }
        }
    }

    /**
     * @return array<string,array{array<string,string>,string}>
     */
    public static function dangerousAttributeProvider(): array
    {
        return [
            'event handler' => [['onclick' => 'steal()'], 'steal()'],
            'event handler, uppercase' => [['ONERROR' => 'steal()'], 'steal()'],
            'javascript url' => [['data-href' => 'javascript:steal()'], 'steal()'],
            'javascript url, split by whitespace' => [['data-href' => "java\tscript:steal()"], 'steal()'],
            'vbscript url' => [['data-href' => 'vbscript:steal()'], 'steal()'],
            'attribute name carrying a second attribute' => [['x" onclick="steal()' => '1'], 'steal()'],
            'attribute name with a space' => [['data-x onclick=steal()' => '1'], 'steal()'],
        ];
    }

    /** Ordinary custom attributes still work — this filters, it doesn't ban the feature. */
    public function testHarmlessCustomAttributesStillRender(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Home', url: '/', htmlAttributes: [
                'data-analytics' => 'nav-home',
                'aria-describedby' => 'nav-help',
                'title' => 'Back to the homepage',
            ]),
        ]);

        $link = $this->query($html, '//a')[0];

        $this->assertSame('nav-home', $link->getAttribute('data-analytics'));
        $this->assertSame('nav-help', $link->getAttribute('aria-describedby'));
        $this->assertSame('Back to the homepage', $link->getAttribute('title'));
    }

    /**
     * The macro's own attributes are not overridable from the bag. A second
     * `href` is not merely ignored by the browser — on a heading, which has
     * none of its own, it would turn a label into a link.
     */
    public function testTheAttributesTheMacroOwnsCannotBeOverridden(): void
    {
        // Named explicitly as well as swept, so tightening or renaming the
        // constant can't quietly stop covering the states that matter.
        foreach (['aria-expanded', 'aria-controls', 'aria-haspopup', 'aria-current', 'aria-hidden', 'tabindex'] as $state) {
            $this->assertContains($state, LinkAttributeHelper::RESERVED_ATTRIBUTES);
        }

        $attributes = array_fill_keys(LinkAttributeHelper::RESERVED_ATTRIBUTES, 'x');

        $html = $this->renderNav([
            $this->node(1, title: 'Explore', url: null, type: 'nonclickable', isClickable: false, htmlAttributes: $attributes),
        ]);

        $span = $this->query($html, '//li/span')[0];

        foreach (LinkAttributeHelper::RESERVED_ATTRIBUTES as $name) {
            $this->assertSame('', $span->getAttribute($name), "\"$name\" belongs to the macro, not to the bag.");
        }

        $this->assertCount(0, $this->query($html, '//a'), 'A heading cannot be turned into a link by an attributes bag.');
    }

    // ---------------------------------------------------------------------
    // Icons and badges as screen-reader content
    // ---------------------------------------------------------------------

    /** A decorative icon is not read out; the item's title is the name. */
    public function testIconsAreHiddenFromScreenReaders(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Home', url: '/', icon: 'class:fa fa-home'),
            $this->node(2, title: 'Docs', url: '/docs', icon: 'asset:' . self::KNOWN_ASSET_ID),
        ]);

        $this->assertSame('true', $this->query($html, '//span[contains(@class, "menu-builder-icon")]')[0]->getAttribute('aria-hidden'));
        $this->assertSame('', $this->query($html, '//img')[0]->getAttribute('alt'));
        $this->assertSame('Home', trim($this->query($html, '//a')[0]->textContent), 'The icon contributes nothing to the name.');
    }

    /** A badge is part of the name it belongs to — "Products New", not a word on its own. */
    public function testABadgeIsPartOfTheItemsAccessibleName(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Products', url: '/products', badge: 'New')]);

        $this->assertSame('Products New', trim($this->query($html, '//a')[0]->textContent));
    }
}

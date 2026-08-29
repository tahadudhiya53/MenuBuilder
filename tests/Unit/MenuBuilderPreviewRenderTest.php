<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\services\MenuBuilderPreviewService;

require_once __DIR__ . '/NavMacroRendering.php';

/**
 * The preview surface, rendered for real.
 *
 * These tests compile the plugin's actual Twig — `_macros/tree.twig` and
 * `preview/_stage.twig` — against real `MenuBuilderNode` objects and assert
 * against the resulting DOM. Nothing here is a string search over source
 * code: what is checked is the markup an editor's browser receives, which is
 * the same markup a front-end template receives, because it comes out of the
 * same macro.
 *
 * A booted Craft app isn't needed for this: the macros consume finished
 * nodes and exactly one Craft touchpoint (`craft.menuBuilder.iconAsset()`,
 * which turns an `asset:` reference into an Asset), stubbed here the way the
 * variable behaves — including returning null for a deleted asset.
 *
 * The *decisions* behind the nodes (visibility, active state, link
 * resolution, mega grouping, dynamic synthesis) belong to the services and
 * are covered by their own suites; this file covers how a resolved node is
 * presented, and that the preview stage adds chrome without touching it.
 */
class MenuBuilderPreviewRenderTest extends TestCase
{
    use NavMacroRendering;

    // ---------------------------------------------------------------------
    // Structure: what a navigation is made of
    // ---------------------------------------------------------------------

    public function testTopLevelItemsRenderAsLinksInOneList(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Home', url: '/'),
            $this->node(2, title: 'News', url: '/news'),
        ]);

        $links = $this->query($html, '/html/body/ul/li/a');

        $this->assertCount(2, $links);
        $this->assertSame('Home', trim($links[0]->textContent));
        $this->assertSame('/', $links[0]->getAttribute('href'));
        $this->assertSame('/news', $links[1]->getAttribute('href'));
    }

    /** Children nest inside their parent's `<li>` — the hierarchy is real markup, not indentation. */
    public function testChildrenNestInsideTheirParentListItem(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Products', url: '/products', children: [
                $this->node(2, title: 'Shoes', url: '/products/shoes', level: 2, children: [
                    $this->node(3, title: 'Running', url: '/products/shoes/running', level: 3),
                ]),
            ]),
        ]);

        $this->assertCount(1, $this->query($html, '/html/body/ul/li/ul'), 'The child list belongs to its parent item.');
        $this->assertCount(1, $this->query($html, '/html/body/ul/li/ul/li/ul/li/a'), 'A third level nests inside the second.');
        $this->assertSame(
            'Running',
            trim($this->query($html, '/html/body/ul/li/ul/li/ul/li/a')[0]->textContent)
        );
    }

    /**
     * A separator is an `<hr>` — whose role *is* separator — inside an
     * ordinary list item. The role is not repeated on the `<li>`: that would
     * both state it twice and put a non-`listitem` child into the list.
     */
    public function testASeparatorIsAnHorizontalRuleInsideAnOrdinaryListItem(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Home', url: '/'),
            $this->node(2, title: '', url: null, type: 'separator', isClickable: false),
        ]);

        $rules = $this->query($html, '/html/body/ul/li/hr');

        $this->assertCount(1, $rules);
        $this->assertSame('', trim($rules[0]->parentNode->textContent), 'A separator carries no label.');
        $this->assertCount(0, $this->query($html, '//li[@role]'), 'The list item keeps its native role.');
        $this->assertCount(1, $this->query($html, '//a'), 'A separator is never a link.');
    }

    /** A heading is a label. The macro must not invent an href for it. */
    public function testANonClickableHeadingRendersAsASpanWithNoLink(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Explore', url: null, type: 'nonclickable', isClickable: false, children: [
                $this->node(2, title: 'Blog', url: '/blog', level: 2),
            ]),
        ]);

        $this->assertCount(0, $this->query($html, '/html/body/ul/li/a'));
        $this->assertSame('Explore', trim($this->query($html, '/html/body/ul/li/span')[0]->textContent));
        $this->assertCount(1, $this->query($html, '/html/body/ul/li/ul/li/a'), 'Its children are still links.');
    }

    /**
     * An unavailable element link on "disable link" resolves with no URL —
     * the item stays in the navigation as a label rather than becoming a
     * link to nowhere.
     */
    public function testAnUnavailableLinkRendersAsALabelRatherThanAnEmptyHref(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Retired page', url: null, type: 'entry', isClickable: false, isLinkAvailable: false),
        ]);

        $this->assertCount(0, $this->query($html, '//a'));
        $this->assertCount(1, $this->query($html, '//li/span'));
    }

    /** A fallback URL is just a resolved URL by the time it reaches the macro. */
    public function testAFallbackUrlRendersAsAnOrdinaryLink(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Moved', url: '/somewhere-else', type: 'entry'),
        ]);

        $this->assertSame('/somewhere-else', $this->query($html, '//a')[0]->getAttribute('href'));
    }

    /**
     * @dataProvider linkShapeProvider
     */
    public function testEveryResolvedLinkShapeReachesTheHrefIntact(string $type, ?string $url): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Item', url: $url, type: $type)]);

        $this->assertSame($url, $this->query($html, '//a')[0]->getAttribute('href'));
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function linkShapeProvider(): array
    {
        return [
            'entry' => ['entry', 'https://example.test/news/latest'],
            'category' => ['category', 'https://example.test/topics/design'],
            'asset' => ['asset', 'https://example.test/uploads/brochure.pdf'],
            'custom url' => ['url', '/pricing'],
            'anchor' => ['anchor', '#contact'],
            'external' => ['url', 'https://elsewhere.test/partners'],
            'mailto' => ['url', 'mailto:hello@example.test'],
            'tel' => ['url', 'tel:+441234567890'],
            'dynamic child' => ['dynamic', '/news/synthesized'],
        ];
    }

    public function testDynamicChildrenRenderAsPartOfTheirParentsSubmenu(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Latest', url: null, type: 'dynamic', isClickable: false, children: [
                $this->node(101, title: 'Post one', url: '/news/one', level: 2, isDynamic: true),
                $this->node(102, title: 'Post two', url: '/news/two', level: 2, isDynamic: true),
            ]),
        ]);

        $links = $this->query($html, '/html/body/ul/li/ul/li/a');

        $this->assertCount(2, $links, 'Synthesized children are ordinary nodes in the same tree.');
        $this->assertSame('Post one', trim($links[0]->textContent));
    }

    // ---------------------------------------------------------------------
    // Active state
    // ---------------------------------------------------------------------

    /**
     * The two flags must stay visually and semantically distinct: the
     * ancestor's branch is marked open, but only the node that *is* the page
     * carries `aria-current`.
     */
    public function testOnlyTheActiveNodeCarriesAriaCurrentWhileItsAncestorIsMerelyMarkedActive(): void
    {
        $child = $this->node(2, title: 'Shoes', url: '/products/shoes', level: 2);
        $child->isActive = true;
        $parent = $this->node(1, title: 'Products', url: '/products', children: [$child]);
        $parent->isActiveAncestor = true;

        $html = $this->renderNav([$parent]);

        $current = $this->query($html, '//a[@aria-current="page"]');

        $this->assertCount(1, $current, 'Exactly one link is the current page.');
        $this->assertSame('/products/shoes', $current[0]->getAttribute('href'));

        $parentLink = $this->query($html, '/html/body/ul/li/a')[0];

        $this->assertSame('', $parentLink->getAttribute('aria-current'), 'An ancestor is not the current page.');
        $this->assertStringContainsString('is-active', $this->query($html, '/html/body/ul/li')[0]->getAttribute('class'));
        $this->assertStringContainsString('is-active', $this->query($html, '//li/ul/li')[0]->getAttribute('class'));
    }

    public function testAnInactiveTreeCarriesNoActiveMarkersAtAll(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Home', url: '/')]);

        $this->assertCount(0, $this->query($html, '//*[@aria-current]'));
        $this->assertCount(0, $this->query($html, '//li[contains(@class, "is-active")]'));
    }

    // ---------------------------------------------------------------------
    // Icons and badges
    // ---------------------------------------------------------------------

    public function testAClassIconRendersAsADecorativeEmptySpan(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Home', url: '/', icon: 'fa fa-home')]);

        $icons = $this->query($html, '//a/span[contains(@class, "menu-builder-icon")]');

        $this->assertCount(1, $icons);
        $this->assertStringContainsString('fa fa-home', $icons[0]->getAttribute('class'));
        $this->assertSame('true', $icons[0]->getAttribute('aria-hidden'), 'A decorative icon must not repeat the label.');
        $this->assertSame('', trim($icons[0]->textContent));
    }

    /** An uploaded SVG is an `<img>`, never inlined markup — script inside it can't run there. */
    public function testAnAssetIconRendersAsAnImageAndIsNeverInlinedAsSvg(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Home', url: '/', icon: 'asset:' . self::KNOWN_ASSET_ID)]);

        $images = $this->query($html, '//a/img[contains(@class, "menu-builder-icon")]');

        $this->assertCount(1, $images);
        $this->assertSame('/uploads/icon.svg', $images[0]->getAttribute('src'));
        $this->assertSame('', $images[0]->getAttribute('alt'), 'A decorative icon has an empty alt.');
        $this->assertCount(0, $this->query($html, '//svg'));
        $this->assertStringNotContainsString('<svg', $html);
    }

    /** A reference to an asset that has since been deleted renders nothing at all. */
    public function testADeletedAssetIconRendersNothing(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Home', url: '/', icon: 'asset:999')]);

        $this->assertCount(0, $this->query($html, '//img'));
        $this->assertSame('Home', trim($this->query($html, '//a')[0]->textContent));
    }

    /** A stored class list that wouldn't validate today fails closed on read, so no icon reaches the markup. */
    public function testAnUnsafeStoredIconClassRendersNoIcon(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Home', url: '/', icon: 'icon" onload="alert(1)')]);

        $this->assertCount(0, $this->query($html, '//span[contains(@class, "menu-builder-icon")]'));
        $this->assertStringNotContainsString('onload', $html);
    }

    public function testABadgeRendersInsideTheLinkWithItsStyleClass(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Products', url: '/products', badge: 'New', badgeStyle: 'info')]);

        $badges = $this->query($html, '//a/span[contains(@class, "menu-builder-badge")]');

        $this->assertCount(1, $badges);
        $this->assertSame('New', $badges[0]->textContent);
        $this->assertStringContainsString('menu-builder-badge--info', $badges[0]->getAttribute('class'));
        $this->assertStringContainsString('Products', $this->query($html, '//a')[0]->textContent, 'The badge is part of the accessible name.');
    }

    public function testAnUnknownBadgeStyleFallsBackToTheBaseClassOnly(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Products', url: '/products', badge: 'New', badgeStyle: 'evil" onmouseover="x')]);

        $class = $this->query($html, '//a/span[contains(@class, "menu-builder-badge")]')[0]->getAttribute('class');

        $this->assertSame('menu-builder-badge', $class);
        $this->assertStringNotContainsString('onmouseover', $html);
    }

    // ---------------------------------------------------------------------
    // Mega menus
    // ---------------------------------------------------------------------

    public function testAMegaMenuRendersANativeDisclosureAndOneListPerColumn(): void
    {
        $html = $this->renderNav([$this->megaParent()]);

        $details = $this->query($html, '//li/details[contains(@class, "menu-builder-megamenu")]');

        $this->assertCount(1, $details);
        $this->assertFalse($details[0]->hasAttribute('open'), 'A panel starts closed.');
        $this->assertCount(1, $this->query($html, '//details/summary[contains(@class, "menu-builder-megamenu-trigger")]'));

        $panel = $this->query($html, '//details/div[contains(@class, "menu-builder-megamenu-panel")]');

        $this->assertCount(1, $panel, 'The panel is inside the element that owns open/closed state.');
        $this->assertSame('group', $panel[0]->getAttribute('role'));

        $columns = $this->query($html, '//div[contains(@class, "menu-builder-megamenu-column")]');

        $this->assertCount(2, $columns, 'One column element per used column.');
    }

    /** Column membership is `MenuBuilderNode::megaMenuColumns()`'s answer, not a decision the markup makes. */
    public function testMegaMenuChildrenLandInTheColumnTheyWereAssignedTo(): void
    {
        $html = $this->renderNav([$this->megaParent()]);

        $firstColumn = $this->query($html, '(//div[contains(@class, "menu-builder-megamenu-column")])[1]//a');
        $secondColumn = $this->query($html, '(//div[contains(@class, "menu-builder-megamenu-column")])[2]//a');

        $this->assertSame(['Latest posts', 'Older posts'], array_map(fn($a) => trim($a->textContent), $firstColumn));
        $this->assertSame(['Jump to'], array_map(fn($a) => trim($a->textContent), $secondColumn));
    }

    public function testAMegaMenuParentDoesNotAlsoRenderAPlainChildList(): void
    {
        $html = $this->renderNav([$this->megaParent()]);

        $this->assertCount(0, $this->query($html, '/html/body/ul/li/ul'), 'The panel replaces the plain submenu.');
    }

    // ---------------------------------------------------------------------
    // Link attributes and escaping
    // ---------------------------------------------------------------------

    public function testTargetAndRelSurviveOntoTheAnchor(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Partner', url: 'https://elsewhere.test', target: '_blank', rel: 'noopener nofollow'),
        ]);

        $link = $this->query($html, '//a')[0];

        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertSame('noopener nofollow', $link->getAttribute('rel'));
    }

    public function testAriaLabelAndTitleAttributeReachTheMarkup(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: 'Docs', url: '/docs', ariaLabel: 'Documentation home', titleAttribute: 'Read the docs'),
        ]);

        $link = $this->query($html, '//a')[0];

        $this->assertSame('Documentation home', $link->getAttribute('aria-label'));
        $this->assertSame('Read the docs', $link->getAttribute('title'));
    }

    /**
     * Editor-authored text is data. A title that looks like markup must
     * arrive as text, not as an element — the macro never uses `|raw`.
     */
    public function testEditorAuthoredTextCannotBecomeMarkup(): void
    {
        $html = $this->renderNav([
            $this->node(1, title: '<script>alert(1)</script>', url: '/x', badge: '<img src=x onerror=alert(1)>'),
        ]);

        $this->assertCount(0, $this->query($html, '//script'));
        $this->assertCount(0, $this->query($html, '//img'));
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** A quote in a resolved URL must not close the attribute it sits in. */
    public function testAQuoteInAUrlCannotBreakOutOfTheHrefAttribute(): void
    {
        $html = $this->renderNav([$this->node(1, title: 'Odd', url: '/search?q="onmouseover="alert(1)')]);

        $link = $this->query($html, '//a')[0];

        $this->assertSame('/search?q="onmouseover="alert(1)', $link->getAttribute('href'));
        $this->assertSame('', $link->getAttribute('onmouseover'), 'The value stayed inside the attribute.');
    }

    /**
     * The markup panel exists to show attributes as text. The expression it
     * uses must escape, not render — the same capture printed unescaped is
     * what the stage does, so the difference is worth pinning behaviourally
     * rather than by reading the template.
     */
    public function testTheRenderedMarkupPanelEscapesTheMarkupInsteadOfRenderingItTwice(): void
    {
        // The panel's exact expression from preview/index.twig, run against
        // the real macro: capture, format, split, print one line per element.
        $twig = $this->twig();
        $rendered = $twig->createTemplate(
            '{% import "_macros/tree.twig" as m %}{% set cap %}{{ m.render(nodes) }}{% endset %}'
            . '<pre><code>{% for line in previewService.formatMarkup(cap|trim)|split("\n") %}<span>{{ line }}</span>'
            . "\n{% endfor %}</code></pre>"
        )->render([
            'nodes' => [$this->node(1, title: 'Home', url: '/', children: [$this->node(2, title: 'Sub', url: '/sub', level: 2)])],
            'previewService' => new MenuBuilderPreviewService(),
        ]);

        $this->assertStringContainsString('&lt;a href=', $rendered);
        $this->assertCount(0, $this->query($rendered, '//pre//a'), 'The markup panel must not render a second navigation.');

        $lines = $this->query($rendered, '//pre/code/span');

        $this->assertGreaterThan(4, count($lines), 'One line per element.');
        $this->assertSame('<ul>', $lines[0]->textContent, 'Each line arrives as text, tags and all.');
        $this->assertSame('</ul>', $lines[count($lines) - 1]->textContent);
    }

    // ---------------------------------------------------------------------
    // The "Rendered markup" panel's formatter
    // ---------------------------------------------------------------------

    /**
     * Run over the real macro's real output, because that is the input the
     * formatter exists for: Twig writes readable templates, not readable
     * output, so the raw markup arrives with an anchor's attributes spread
     * down a dozen lines and long runs of blank ones.
     */
    public function testTheMarkupPanelFormatsRealMacroOutputIntoOneElementPerLine(): void
    {
        $raw = $this->renderNav([
            $this->node(1, title: 'Products', url: '/products', badge: 'New', children: [
                $this->node(2, title: 'Shoes', url: '/products/shoes', level: 2),
            ]),
        ]);

        $formatted = MenuBuilderPreviewService::formatMarkup($raw);
        $lines = explode("\n", $formatted);

        $this->assertNotEmpty(array_filter(explode("\n", $raw), static fn(string $line): bool => trim($line) === ''), 'The raw macro output has blank lines to begin with.');
        $this->assertSame([], array_filter($lines, static fn(string $line): bool => trim($line) === ''), 'The formatted panel has none.');

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(1, substr_count($line, '<a '), 'One element per line.');
        }

        $this->assertSame('<ul>', $lines[0]);
        $this->assertSame('    <li>', $lines[1]);
        $this->assertStringStartsWith('        <a href="/products"', $lines[2], 'An anchor keeps its attributes on one line.');
        $this->assertSame('</ul>', end($lines));
    }

    public function testTheFormatterIndentsByDepthAndClosesBackOut(): void
    {
        $formatted = MenuBuilderPreviewService::formatMarkup('<ul><li><a href="/">Home</a><ul><li>Deep</li></ul></li></ul>');

        $this->assertSame([
            '<ul>',
            '    <li>',
            '        <a href="/">Home</a>',
            '        <ul>',
            '            <li>Deep</li>',
            '        </ul>',
            '    </li>',
            '</ul>',
        ], explode("\n", $formatted));
    }

    /** A separator's `<hr>` closes itself, so it must not push everything after it one level in. */
    public function testAVoidElementDoesNotOpenAnIndentLevel(): void
    {
        $formatted = MenuBuilderPreviewService::formatMarkup(
            $this->renderNav([
                $this->node(1, title: '', url: null, type: 'separator', isClickable: false),
                $this->node(2, title: 'Home', url: '/'),
            ])
        );

        $this->assertStringContainsString("    <li>\n        <hr>\n    </li>", $formatted);
        $this->assertStringContainsString("\n    <li>\n        <a href=\"/\"", $formatted, 'The item after a separator is still at depth 2.');
    }

    /**
     * The formatter runs over *rendered* markup, where editor text is already
     * escaped — so a title reading like a tag stays text and cannot be
     * mistaken for one, in the formatter or in the panel.
     */
    public function testTextThatLooksLikeMarkupIsNotTreatedAsAnElement(): void
    {
        $formatted = MenuBuilderPreviewService::formatMarkup(
            $this->renderNav([$this->node(1, title: '<b>bold</b>', url: '/x')])
        );

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $formatted);
        $this->assertStringNotContainsString('<b>', $formatted);
        $this->assertSame(
            ['<ul>', '    <li>', '    </li>', '</ul>'],
            array_values(array_filter(explode("\n", $formatted), static fn(string $line): bool => !str_contains($line, '<a '))),
            'The escaped text sat inside the anchor and opened no level of its own.'
        );
    }

    public function testTheFormatterChangesSpacingOnly(): void
    {
        $raw = $this->renderNav([$this->megaParent()]);
        $formatted = MenuBuilderPreviewService::formatMarkup($raw);

        $strip = static fn(string $html): string => (string)preg_replace('/\s+/', '', $html);

        $this->assertSame(
            $strip($raw),
            $strip($formatted),
            'Re-indenting must not add, drop or reorder anything.'
        );
    }

    public function testFormattingAnEmptyStringIsEmpty(): void
    {
        $this->assertSame('', MenuBuilderPreviewService::formatMarkup(''));
        $this->assertSame('', MenuBuilderPreviewService::formatMarkup("   \n  "));
    }

    // ---------------------------------------------------------------------
    // The stage around the navigation
    // ---------------------------------------------------------------------

    public function testTheStageWrapsTheNavigationInALandmarkWithoutAlteringIt(): void
    {
        $nodes = [$this->node(1, title: 'Home', url: '/', badge: 'New')];
        $stage = $this->renderStage($nodes);
        $nav = $this->renderNav($nodes);

        $this->assertCount(1, $this->query($stage, '//nav[@aria-label="Preview of Main"]'));
        $this->assertCount(1, $this->query($stage, '//nav//a[@href="/"]'), 'The navigation renders as markup inside the landmark, not as escaped source.');
        $this->assertCount(1, $this->query($stage, '//nav//span[contains(@class, "menu-builder-badge")]'));
        $this->assertStringContainsString(trim($nav), $stage, 'The stage adds chrome around the macro output, never inside it.');
    }

    public function testTheDesktopStageRendersNoMobileChrome(): void
    {
        $stage = $this->renderStage([$this->node(1, title: 'Home', url: '/')]);

        $this->assertCount(1, $this->query($stage, '//div[contains(@class, "menu-builder-preview-stage--desktop")]'));
        $this->assertCount(0, $this->query($stage, '//button[@data-mb-preview-burger]'));
    }

    /** The mobile viewport gets a real disclosure button, wired to the nav it controls. */
    public function testTheMobileStageAddsADisclosureButtonBoundToTheNavigation(): void
    {
        $stage = $this->renderStage([$this->node(1, title: 'Home', url: '/')], isMobile: true);

        $this->assertCount(1, $this->query($stage, '//div[contains(@class, "menu-builder-preview-stage--mobile")]'));

        $burger = $this->query($stage, '//button[@data-mb-preview-burger]');

        $this->assertCount(1, $burger);
        $this->assertSame('true', $burger[0]->getAttribute('aria-expanded'));
        $this->assertSame(
            $burger[0]->getAttribute('aria-controls'),
            $this->query($stage, '//nav')[0]->getAttribute('id'),
            'The toggle must control the navigation it sits above.'
        );
    }

    /** Decorative furniture must not be read out or become part of the navigation. */
    public function testTheStageChromeIsHiddenFromAssistiveTechnology(): void
    {
        $stage = $this->renderStage([$this->node(1, title: 'Home', url: '/')]);

        $this->assertSame('true', $this->query($stage, '//div[contains(@class, "menu-builder-preview-chrome")]')[0]->getAttribute('aria-hidden'));
        $this->assertSame('true', $this->query($stage, '//div[contains(@class, "menu-builder-preview-canvas")]')[0]->getAttribute('aria-hidden'));
        $this->assertCount(0, $this->query($stage, '//nav//div[contains(@class, "menu-builder-preview-skeleton")]'));
    }

    // ---------------------------------------------------------------------
    // Placement: the menu is in the header, or it is in the footer
    // ---------------------------------------------------------------------

    /** Header placement: the real navigation is in the masthead, the footer is shapes. */
    public function testAHeaderMenuRendersInTheMastheadAndLeavesTheFooterAsPlaceholders(): void
    {
        $stage = $this->renderStage([$this->node(1, title: 'Home', url: '/')]);

        $this->assertCount(1, $this->query($stage, '//header//nav//a[@href="/"]'));
        $this->assertCount(0, $this->query($stage, '//footer//nav'));
        $this->assertCount(1, $this->query($stage, '//footer//span[contains(@class, "menu-builder-preview-nav-placeholder")]'));
        $this->assertCount(1, $this->query($stage, '//div[contains(@class, "menu-builder-preview-stage--header")]'));
    }

    /** Footer placement: the same markup, in the footer, with the masthead as shapes. */
    public function testAFooterMenuRendersInTheFooterAndLeavesTheMastheadAsPlaceholders(): void
    {
        $stage = $this->renderStage([$this->node(1, title: 'Privacy', url: '/privacy')], isFooter: true);

        $this->assertCount(1, $this->query($stage, '//footer//nav//a[@href="/privacy"]'));
        $this->assertCount(0, $this->query($stage, '//header//nav'));
        $this->assertCount(1, $this->query($stage, '//header//span[contains(@class, "menu-builder-preview-nav-placeholder")]'));
        $this->assertCount(1, $this->query($stage, '//div[contains(@class, "menu-builder-preview-stage--footer")]'));
    }

    /** One navigation on the page, wherever it sits — and the placeholders are never read out. */
    public function testOnlyOneNavigationIsRenderedAndThePlaceholdersAreDecorative(): void
    {
        foreach ([false, true] as $isFooter) {
            $stage = $this->renderStage([$this->node(1, title: 'Home', url: '/')], isFooter: $isFooter);

            $this->assertCount(1, $this->query($stage, '//nav'), 'A page has one preview navigation.');
            $this->assertCount(1, $this->query($stage, '//a'), 'The placeholders are shapes, never links.');

            foreach ($this->query($stage, '//span[contains(@class, "menu-builder-preview-nav-placeholder")]') as $placeholder) {
                $this->assertSame('true', $placeholder->getAttribute('aria-hidden'));
                $this->assertSame('', trim($placeholder->textContent));
            }
        }
    }

    /** A footer has no masthead disclosure to own, so the mobile toggle belongs to the header only. */
    public function testTheMobileToggleIsOnlyRenderedForAHeaderMenu(): void
    {
        $this->assertCount(1, $this->query($this->renderStage([$this->node(1, url: '/')], isMobile: true), '//button[@data-mb-preview-burger]'));
        $this->assertCount(0, $this->query($this->renderStage([$this->node(1, url: '/')], isMobile: true, isFooter: true), '//button[@data-mb-preview-burger]'));
    }

    /** Header furniture is a shape, not a word: a labelled button would read as a menu item. */
    public function testTheHeaderPlaceholderIsHiddenAndCarriesNoText(): void
    {
        $stage = $this->renderStage([$this->node(1, title: 'Home', url: '/')]);
        $slot = $this->query($stage, '//span[contains(@class, "menu-builder-preview-header-slot")]');

        $this->assertCount(1, $slot);
        $this->assertSame('true', $slot[0]->getAttribute('aria-hidden'));
        $this->assertSame('', trim($slot[0]->textContent));
        $this->assertCount(0, $this->query($stage, '//nav//span[contains(@class, "menu-builder-preview-header-slot")]'), 'Chrome never sits inside the navigation.');
    }

    /** The stage is presentation. It must not smuggle script into the control panel. */
    public function testTheStageContainsNoInlineScript(): void
    {
        $stage = $this->renderStage([$this->node(1, title: 'Home', url: '/<script>')], isMobile: true);

        $this->assertCount(0, $this->query($stage, '//script'));
        $this->assertStringNotContainsString('<script', $stage);
    }
}

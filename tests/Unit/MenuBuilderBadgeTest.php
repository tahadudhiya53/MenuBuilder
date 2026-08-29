<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\helpers\BadgeHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\services\MenuBuilderCacheService;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * The badge model: "Products [NEW]".
 *
 * Two stored values on two existing pieces of storage — free text in the
 * `badge` column, an optional style from a closed enum in
 * `metadata['badgeStyle']` — and one rule that decides everything about
 * how they're treated: the text is *text* and is escaped where it is
 * rendered; the style is the only half that reaches a class attribute,
 * and it fails closed against the allowlist.
 *
 * The rendering half of that rule is tested by actually rendering the
 * bundled macro through Twig rather than by grepping it, because "this
 * escapes" is a property of the output, not of the source.
 */
class MenuBuilderBadgeTest extends TestCase
{
    private const MACRO_DIR = __DIR__ . '/../../src/templates/_macros/';

    private function urlItem(): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Products';
        $item->customUrl = 'https://example.com';

        return $item;
    }

    private function nodeWithBadge(?string $badge, ?string $style = null, bool $clickable = true): MenuBuilderNode
    {
        return new MenuBuilderNode(
            id: 1,
            handle: null,
            type: 'url',
            title: 'Products',
            url: 'https://example.com',
            isClickable: $clickable,
            isLinkAvailable: true,
            target: '_self',
            rel: null,
            cssClass: null,
            htmlId: null,
            htmlAttributes: [],
            ariaLabel: null,
            titleAttribute: null,
            icon: null,
            badge: $badge,
            description: null,
            image: null,
            featured: false,
            level: 1,
            badgeStyle: $style,
        );
    }

    /**
     * Renders the bundled `render()` macro over one node, through a real
     * Twig environment with autoescaping on — the same setting Craft runs
     * templates under.
     */
    private function renderMacro(MenuBuilderNode $node): string
    {
        $twig = new Environment(new FilesystemLoader(self::MACRO_DIR), ['autoescape' => 'html', 'cache' => false]);

        // Craft's `t` filter, which the macro uses for the "opens in a new
        // tab" hint. Reduced to the passthrough this test needs.
        $twig->addFilter(new TwigFilter('t', static fn(string $message): string => $message));

        return $twig->createTemplate(
            '{% import "tree.twig" as m %}{{ m.render(nodes) }}'
        )->render(['nodes' => [$node]]);
    }

    private function source(string $path): string
    {
        return (string)file_get_contents(__DIR__ . '/../../' . $path);
    }

    // ---------------------------------------------------------------------
    // The text half
    // ---------------------------------------------------------------------

    /** @dataProvider badgeTextProvider */
    public function testBadgeTextIsStoredExactlyAsTyped(string $typed): void
    {
        $this->assertSame($typed, BadgeHelper::normalizeText($typed));
        $this->assertTrue(BadgeHelper::hasBadge($typed));
    }

    public static function badgeTextProvider(): array
    {
        return [
            'NEW' => ['NEW'],
            'SALE' => ['SALE'],
            'BETA' => ['BETA'],
            'lower case' => ['new'],
            'a number' => ['50% off'],
            'an ampersand' => ['Tea & Coffee'],
            'quotes' => ['"hot"'],
            'a less-than that is not markup' => ['<3'],
            'an emoji' => ['🔥 Hot'],
            'accents' => ['Nouveauté'],
        ];
    }

    /**
     * An empty badge is not a badge, however it arrives — blank, spaces,
     * or the newlines and tabs a paste smuggles in.
     */
    public function testAnEmptyBadgeIsNoBadge(): void
    {
        foreach ([null, '', '   ', "\n\t", "\u{00a0}"] as $empty) {
            $this->assertNull(BadgeHelper::normalizeText($empty), var_export($empty, true));
            $this->assertNull(BadgeHelper::text($empty));
            $this->assertFalse(BadgeHelper::hasBadge($empty));
        }
    }

    public function testWhitespaceInsideABadgeIsCollapsedSoOneBadgeIsOneValue(): void
    {
        $this->assertSame('JUST IN', BadgeHelper::normalizeText("  JUST\n\tIN  "));
        $this->assertSame('JUST IN', BadgeHelper::normalizeText('JUST   IN'));
    }

    /**
     * Markup typed into the badge field is *kept*, as text. Stripping it
     * here would mangle legitimate badges while adding nothing — the
     * safety is the escaping at the render boundary, tested below.
     */
    public function testMarkupTypedIntoABadgeIsKeptAsTextRatherThanSanitizedAway(): void
    {
        $injection = '<script>alert(1)</script>';

        $this->assertSame($injection, BadgeHelper::normalizeText($injection));
    }

    /**
     * The one limit on badge text is the column's: varchar(255). Without
     * the `max` rule an over-long badge passed validation and then failed
     * at the database with no field error to explain it.
     */
    public function testAnOverLongBadgeIsRejectedWithAFieldError(): void
    {
        $item = $this->urlItem();
        $item->badge = str_repeat('a', 256);

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('badge', $item->getErrors());
    }

    public function testALongButStorableBadgeIsAccepted(): void
    {
        $item = $this->urlItem();
        $item->badge = str_repeat('a', 255);

        $this->assertTrue($item->validate(), print_r($item->getErrors(), true));
    }

    public function testValidationNormalizesTheBadgeOnTheWayIn(): void
    {
        $item = $this->urlItem();
        $item->badge = "  NEW\n ";
        $item->validate();

        $this->assertSame('NEW', $item->badge);

        $blank = $this->urlItem();
        $blank->badge = '   ';
        $blank->validate();

        $this->assertNull($blank->badge);
        $this->assertFalse($blank->hasBadge());
    }

    // ---------------------------------------------------------------------
    // The style half — a closed enum, failing closed
    // ---------------------------------------------------------------------

    /** @dataProvider knownStyleProvider */
    public function testAKnownStyleIsKept(string $style): void
    {
        $this->assertSame($style, BadgeHelper::style($style));
        $this->assertTrue(BadgeHelper::isValidStyle($style));
        $this->assertSame('menu-builder-badge menu-builder-badge--' . $style, BadgeHelper::cssClass($style));
    }

    public static function knownStyleProvider(): array
    {
        return [
            'info' => ['info'],
            'success' => ['success'],
            'warning' => ['warning'],
            'critical' => ['critical'],
        ];
    }

    /** The default is "no modifier class", not a class nothing styles. */
    public function testTheDefaultStyleReadsBackAsNoStyle(): void
    {
        foreach ([null, '', 'default', ' DEFAULT '] as $value) {
            $this->assertNull(BadgeHelper::style($value), var_export($value, true));
            $this->assertSame(BadgeHelper::BASE_CLASS, BadgeHelper::cssClass($value));
        }
    }

    /**
     * Fail closed: a style that isn't in the allowlist — a legacy row, a
     * hand-written database update, a crafted post — reads back as null
     * rather than as something that lands in a class attribute.
     *
     * @dataProvider unknownStyleProvider
     */
    public function testAnUnknownStyleReadsBackAsNoStyleRatherThanAsMarkup(mixed $stored): void
    {
        $this->assertNull(BadgeHelper::style($stored));
        $this->assertSame(BadgeHelper::BASE_CLASS, BadgeHelper::cssClass($stored));
    }

    public static function unknownStyleProvider(): array
    {
        return [
            'an invented style' => ['neon'],
            'a class break-out attempt' => ['info" onclick="alert(1)'],
            'markup' => ['<script>'],
            'an array' => [['info']],
            'an int' => [7],
            'a bool' => [true],
        ];
    }

    public function testAnUnknownStyleIsRejectedAtValidationRatherThanStoredSilently(): void
    {
        $item = $this->urlItem();
        $item->badge = 'NEW';
        $item->metadata = ['badgeStyle' => 'neon'];

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('badge', $item->getErrors());
    }

    public function testAKnownStyleValidatesAndReadsBackOffTheItem(): void
    {
        $item = $this->urlItem();
        $item->badge = 'SALE';
        $item->metadata = ['badgeStyle' => 'critical'];

        $this->assertTrue($item->validate(), print_r($item->getErrors(), true));
        $this->assertSame('critical', $item->badgeStyle());
        $this->assertTrue($item->hasBadge());
    }

    public function testAnItemWithNoBadgeConfigIsSimplyUnbadged(): void
    {
        $item = $this->urlItem();

        $this->assertTrue($item->validate(), print_r($item->getErrors(), true));
        $this->assertFalse($item->hasBadge());
        $this->assertNull($item->badgeStyle());
    }

    // ---------------------------------------------------------------------
    // The node
    // ---------------------------------------------------------------------

    public function testTheNodeExposesTheBadgeToTwig(): void
    {
        $node = $this->nodeWithBadge('NEW', 'info');

        $this->assertSame('NEW', $node->badge);
        $this->assertSame('info', $node->badgeStyle);
        $this->assertTrue($node->hasBadge());
        $this->assertSame('menu-builder-badge menu-builder-badge--info', $node->badgeClass());
    }

    /** A style with no text is not a badge — nothing to render, so nothing renders. */
    public function testAStyleWithoutTextIsNotABadge(): void
    {
        $node = $this->nodeWithBadge(null, 'info');

        $this->assertFalse($node->hasBadge());
        $this->assertStringNotContainsString('menu-builder-badge', $this->renderMacro($node));
    }

    /** withChildren() copies the node for the per-request pipeline; readonly badge state has to survive it. */
    public function testTheBadgeSurvivesTheNodeCopyMadeForVisibilityAndActiveState(): void
    {
        $node = $this->nodeWithBadge('NEW', 'info');
        $copy = $node->withChildren([$this->nodeWithBadge('SALE', 'critical')]);

        $this->assertSame('NEW', $copy->badge);
        $this->assertSame('info', $copy->badgeStyle);
        $this->assertSame('SALE', $copy->children[0]->badge);
        $this->assertSame('critical', $copy->children[0]->badgeStyle);
    }

    // ---------------------------------------------------------------------
    // The bundled macro — rendered, not grepped
    // ---------------------------------------------------------------------

    public function testTheMacroRendersTheBadgeInsideTheLabel(): void
    {
        $html = $this->renderMacro($this->nodeWithBadge('NEW', 'info'));

        $this->assertStringContainsString('<span class="menu-builder-badge menu-builder-badge--info">NEW</span>', $html);
        // Inside the link, so the badge is part of the accessible name.
        $this->assertMatchesRegularExpression('/<a [^>]*>.*NEW.*<\/a>/s', $html);
    }

    public function testANonClickableItemGetsItsBadgeToo(): void
    {
        $html = $this->renderMacro($this->nodeWithBadge('BETA', null, clickable: false));

        $this->assertStringContainsString('<span class="menu-builder-badge">BETA</span>', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testAnEmptyBadgeRendersNothingAtAll(): void
    {
        foreach ([null, '', '   '] as $empty) {
            $html = $this->renderMacro($this->nodeWithBadge($empty));

            $this->assertStringNotContainsString('menu-builder-badge', $html);
        }
    }

    /**
     * The whole point. Every one of these renders as *text* — the badge
     * says `<script>alert(1)</script>`, it does not run it, and it cannot
     * close the span or break out into an attribute.
     *
     * @dataProvider injectionProvider
     */
    public function testBadgeTextIsEscapedWhenRendered(string $badge, string $expected): void
    {
        $html = $this->renderMacro($this->nodeWithBadge($badge));

        $this->assertStringContainsString('<span class="menu-builder-badge">' . $expected . '</span>', $html);
        // The raw text never survives as markup — and the badge text is not
        // asserted *absent* (an escaped `onerror=` is legitimately still the
        // word "onerror" on screen): what must hold is that the payload
        // opened no tag and no attribute of its own. Counting the markup
        // delimiters against a benign badge is what says that.
        $control = $this->renderMacro($this->nodeWithBadge('SAFE'));

        $this->assertSame(substr_count($control, '<'), substr_count($html, '<'), 'The badge opened a tag.');
        $this->assertSame(substr_count($control, '>'), substr_count($html, '>'), 'The badge closed a tag.');
        $this->assertSame(substr_count($control, '"'), substr_count($html, '"'), 'The badge broke out of an attribute.');
        $this->assertStringNotContainsString('<script', $html);
    }

    public static function injectionProvider(): array
    {
        return [
            'a script tag' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
            'an img onerror' => ['<img src=x onerror=alert(1)>', '&lt;img src=x onerror=alert(1)&gt;'],
            'a span break-out' => ['</span><script>alert(1)</script>', '&lt;/span&gt;&lt;script&gt;alert(1)&lt;/script&gt;'],
            'an attribute break-out' => ['" onmouseover="alert(1)', '&quot; onmouseover=&quot;alert(1)'],
            'a javascript url' => ['javascript:alert(1)', 'javascript:alert(1)'],
            'an ampersand' => ['Tea & Coffee', 'Tea &amp; Coffee'],
        ];
    }

    /** Very long badge text is still just text — no truncation, no markup, nothing broken. */
    public function testAVeryLongBadgeRendersAsOneEscapedRunOfText(): void
    {
        $long = str_repeat('VERY LONG BADGE ', 15);
        $node = $this->nodeWithBadge(BadgeHelper::normalizeText($long));
        $html = $this->renderMacro($node);

        $this->assertStringContainsString(trim($long), $html);
        $this->assertSame(1, substr_count($html, 'menu-builder-badge'));
    }

    /** An unknown style can never reach the class attribute, even straight off a node. */
    public function testAnUnknownStyleOnANodeNeverReachesTheClassAttribute(): void
    {
        $html = $this->renderMacro($this->nodeWithBadge('NEW', 'info" onclick="alert(1)'));

        $this->assertStringContainsString('<span class="menu-builder-badge">NEW</span>', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    /**
     * Two call sites: the link branch and the heading branch. Not the
     * mega-menu trigger — that button no longer repeats the item's label at
     * all (it carries a caret and an accessible name of its own), so a badge
     * there would be the item's badge announced twice.
     */
    public function testTheMacroBadgesEveryLabelBranch(): void
    {
        $macro = $this->source('src/templates/_macros/tree.twig');

        $this->assertSame(2, substr_count($macro, 'self.badge(node)'));
        $this->assertStringContainsString('{% macro badge(node) %}', $macro);
        // The class list comes from the allowlist, never from stored state.
        $this->assertStringContainsString('node.badgeClass()', $macro);
        $this->assertStringNotContainsString('node.badgeStyle }}', $macro);
        $this->assertStringNotContainsString('node.badge|raw', $macro);
        $this->assertStringNotContainsString('node.badge|striptags', $macro);
    }

    // ---------------------------------------------------------------------
    // Persistence, duplication and the CP form
    // ---------------------------------------------------------------------

    /**
     * Both halves are carried by storage that already existed — the
     * `badge` column and the `metadata` blob — which is why this phase
     * needs no migration.
     */
    public function testBothHalvesRideOnStorageThatAlreadyExists(): void
    {
        $install = $this->source('src/migrations/Install.php');

        $this->assertStringContainsString("'badge' => \$this->string(255),", $install);
        $this->assertStringContainsString("'metadata' => \$this->text()->notNull(),", $install);
        // No new column for the style.
        $this->assertStringNotContainsString('badgeStyle', $install);
    }

    public function testTheItemServicePersistsAndReadsBackBothHalves(): void
    {
        $items = $this->source('src/services/MenuBuilderItemService.php');

        $this->assertStringContainsString('$record->badge = $item->badge;', $items);
        $this->assertStringContainsString('$item->badge = $record->badge;', $items);
        $this->assertStringContainsString('$record->metadata = ', $items);
    }

    /** Duplication copies both halves — the text column and the metadata the style rides in. */
    public function testDuplicationCarriesTheBadgeAndItsStyle(): void
    {
        $items = $this->source('src/services/MenuBuilderItemService.php');

        $this->assertStringContainsString('$clone->badge = $original->badge;', $items);
        $this->assertStringContainsString('$clone->metadata = $original->metadata;', $items);
    }

    /** The resolver reads through the fail-closed helpers rather than handing raw column values to Twig. */
    public function testTheResolverReadsBothHalvesThroughTheHelper(): void
    {
        $resolver = $this->source('src/services/MenuBuilderResolver.php');

        $this->assertStringContainsString('badge: BadgeHelper::text($item->badge),', $resolver);
        $this->assertStringContainsString("badgeStyle: BadgeHelper::style(\$item->metadata['badgeStyle'] ?? null),", $resolver);
    }

    public function testTheCpFormPostsBothInputsAndTheControllerStoresThem(): void
    {
        $fields = $this->source('src/templates/items/_fields.twig');

        $this->assertStringContainsString("name: 'badge'", $fields);
        $this->assertStringContainsString("name: 'badgeStyle'", $fields);

        $controller = $this->source('src/controllers/ItemsController.php');

        $this->assertStringContainsString('BadgeHelper::normalizeText($this->bodyString(\'badge\'))', $controller);
        $this->assertStringContainsString("\$metadata['badgeStyle'] = \$badgeStyle;", $controller);
    }

    /** The CP tree previews the badge, escaped like everywhere else — no `|raw` in the row. */
    public function testTheCpTreePreviewsTheBadgeWithoutRawOutput(): void
    {
        $row = $this->source('src/templates/dashboard/_items.twig');

        $this->assertStringContainsString('item.hasBadge()', $row);
        $this->assertStringContainsString('{{ item.badge }}', $row);
        $this->assertStringNotContainsString('item.badge|raw', $row);
    }

    // ---------------------------------------------------------------------
    // What a badge must NOT touch
    // ---------------------------------------------------------------------

    /**
     * A badge is presentation. It must not reach the link resolvers, the
     * active-state resolver or the visibility rules — if it did, an
     * editor's decoration would start changing where an item points, when
     * it highlights, or who can see it.
     */
    public function testABadgeIsNotConsultedByLinksActiveStateOrVisibility(): void
    {
        $paths = array_merge(
            glob(__DIR__ . '/../../src/linktypes/*.php') ?: [],
            glob(__DIR__ . '/../../src/visibility/*.php') ?: [],
            [__DIR__ . '/../../src/services/MenuBuilderActiveResolver.php'],
        );

        foreach ($paths as $path) {
            $this->assertStringNotContainsStringIgnoringCase(
                'badge',
                (string)file_get_contents($path),
                basename($path) . ' should know nothing about badges.'
            );
        }
    }

    /** Two nodes differing only in their badge resolve to the same URL and the same active state. */
    public function testChangingABadgeChangesNeitherUrlNorActiveState(): void
    {
        $plain = $this->nodeWithBadge(null);
        $badged = $this->nodeWithBadge('NEW', 'info');

        $this->assertSame($plain->url, $badged->url);
        $this->assertSame($plain->isClickable, $badged->isClickable);
        $this->assertSame($plain->isActive, $badged->isActive);
        $this->assertSame($plain->isActiveOrAncestor(), $badged->isActiveOrAncestor());
    }

    /**
     * The badge is menu-wide presentation, not a per-user decision, so it
     * belongs in the cached payload — and the cache key already accounts
     * for the new property, because the payload version is a hash of the
     * node's property list. That's what keeps an entry written before this
     * phase from unserializing into a node with an uninitialized readonly
     * `badgeStyle`.
     */
    public function testTheCachedPayloadVersionCoversTheNewNodeProperty(): void
    {
        $this->assertContains(
            MenuBuilderNode::class,
            MenuBuilderCacheService::PAYLOAD_CLASSES,
            'The node is the cached payload, so its property list is what the key digests.'
        );

        // That a new property rotates the digest is pinned by
        // MenuBuilderCacheTest; what this phase adds is the property.
        $properties = array_map(
            fn(\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(MenuBuilderNode::class))->getProperties(\ReflectionProperty::IS_PUBLIC)
        );

        $this->assertContains('badge', $properties);
        $this->assertContains('badgeStyle', $properties);
    }
}

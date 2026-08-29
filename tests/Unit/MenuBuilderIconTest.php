<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\helpers\IconHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;

/**
 * The icon model.
 *
 * One column (`icon`), three forms (none / class list / `asset:<id>`), and
 * one hard rule: markup never gets in, and never comes back out. The
 * "comes back out" half matters independently — a row written before this
 * grammar existed, or written straight into the database, must still read
 * back as "no icon" rather than as something a template could emit.
 */
class MenuBuilderIconTest extends TestCase
{
    private function urlItem(): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = 1;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->title = 'Products';
        $item->customUrl = 'https://example.com';

        return $item;
    }

    private function nodeWithIcon(?string $icon): MenuBuilderNode
    {
        return new MenuBuilderNode(
            id: 1,
            handle: null,
            type: 'url',
            title: 'Products',
            url: 'https://example.com',
            isClickable: true,
            isLinkAvailable: true,
            target: '_self',
            rel: null,
            cssClass: null,
            htmlId: null,
            htmlAttributes: [],
            ariaLabel: null,
            titleAttribute: null,
            icon: $icon,
            badge: null,
            description: null,
            image: null,
            featured: false,
            level: 0,
        );
    }

    // ---------------------------------------------------------------------
    // The grammar
    // ---------------------------------------------------------------------

    public function testAnEmptyIconIsNoIcon(): void
    {
        foreach ([null, '', '   ', "\n\t"] as $empty) {
            $this->assertNull(IconHelper::normalize($empty));
            $this->assertNull(IconHelper::type($empty));
            $this->assertNull(IconHelper::classValue($empty));
            $this->assertNull(IconHelper::assetId($empty));
            $this->assertTrue(IconHelper::isValid($empty));
        }
    }

    public function testABareValueIsAClassIcon(): void
    {
        $this->assertSame(IconHelper::TYPE_CLASS, IconHelper::type('icon-cart'));
        $this->assertSame('icon-cart', IconHelper::classValue('icon-cart'));
        $this->assertNull(IconHelper::assetId('icon-cart'));
    }

    /**
     * Every icon stored before this grammar existed was a free-typed
     * handle/class — those rows must keep meaning exactly what they meant.
     *
     * @dataProvider legacyClassProvider
     */
    public function testExistingFreeTypedIconsKeepWorking(string $stored): void
    {
        $this->assertSame(IconHelper::TYPE_CLASS, IconHelper::type($stored));
        $this->assertSame($stored, IconHelper::classValue($stored));
    }

    /** @return array<string,array{string}> */
    public static function legacyClassProvider(): array
    {
        return [
            'icon font handle' => ['icon-cart'],
            'multi-class' => ['fa fa-cart'],
            'sprite path' => ['heroicons/outline/home'],
            'namespaced handle' => ['mdi:home'],
            'dotted' => ['icons.cart'],
            'underscored' => ['icon_cart'],
        ];
    }

    public function testAnAssetReferenceIsAnAssetIcon(): void
    {
        $this->assertSame(IconHelper::TYPE_ASSET, IconHelper::type('asset:42'));
        $this->assertSame(42, IconHelper::assetId('asset:42'));
        $this->assertNull(IconHelper::classValue('asset:42'));
    }

    public function testNormalizationCollapsesEquivalentSpellingsToOneStoredValue(): void
    {
        $this->assertSame('icon-cart', IconHelper::normalize('  icon-cart  '));
        $this->assertSame('icon-cart', IconHelper::normalize('class:icon-cart'));
        $this->assertSame('icon-cart', IconHelper::normalize('CLASS: icon-cart'));
        $this->assertSame('fa fa-cart', IconHelper::normalize("fa \t fa-cart"));
        $this->assertSame('asset:42', IconHelper::normalize('ASSET:42'));
        $this->assertSame('asset:42', IconHelper::normalize(' asset:42 '));
    }

    public function testAZeroOrNegativeAssetIdIsNotAnAssetIcon(): void
    {
        // 'asset:0' can't reference anything, so it falls through to the
        // class form — where the colon rule still has to hold.
        $this->assertNull(IconHelper::assetId('asset:0'));
        $this->assertSame(IconHelper::TYPE_CLASS, IconHelper::type('asset:0'));
    }

    // ---------------------------------------------------------------------
    // Markup never gets in
    // ---------------------------------------------------------------------

    /** @return array<string,array{string}> */
    public static function unsafeIconProvider(): array
    {
        return [
            'raw svg' => ['<svg onload="alert(1)"></svg>'],
            'svg with script' => ['<svg><script>alert(1)</script></svg>'],
            'attribute break, double quote' => ['icon" onclick="alert(1)'],
            'attribute break, single quote' => ["icon' onclick='alert(1)"],
            'unquoted attribute break' => ['icon onclick=alert(1)'],
            'angle bracket' => ['icon<script>'],
            'entity' => ['icon&lt;'],
            'javascript scheme' => ['javascript:alert(1)'],
            'javascript scheme, uppercase' => ['JAVASCRIPT:alert(1)'],
            'vbscript scheme' => ['vbscript:msgbox(1)'],
            'data scheme' => ['data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='],
            'file scheme' => ['file:///etc/passwd'],
            'backtick' => ['icon`cart`'],
            'backslash' => ['icon\\cart'],
        ];
    }

    /** @dataProvider unsafeIconProvider */
    public function testAnUnsafeIconIsRejectedOnSave(string $icon): void
    {
        $this->assertFalse(IconHelper::isValid($icon), "Expected $icon to be rejected.");

        $item = $this->urlItem();
        $item->icon = $icon;

        $this->assertFalse($item->validate(), "Expected an item with icon $icon to fail validation.");
        $this->assertArrayHasKey('icon', $item->getErrors());
    }

    /**
     * The other half: even if such a value reached the column anyway — a
     * legacy row, a direct database write — nothing hands it to a template.
     *
     * @dataProvider unsafeIconProvider
     */
    public function testAnUnsafeStoredIconReadsBackAsNoIcon(string $icon): void
    {
        $this->assertNull(IconHelper::classValue($icon));
        $this->assertNull(IconHelper::type($icon));

        $node = $this->nodeWithIcon($icon);

        $this->assertNull($node->iconClass());
        $this->assertNull($node->iconType());
        $this->assertNull($node->iconAssetId());
        $this->assertFalse($node->hasIcon());
    }

    public function testAValidIconStillSavesAndIsNormalizedInPlace(): void
    {
        $item = $this->urlItem();
        $item->icon = ' class:fa  fa-cart ';

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
        $this->assertSame('fa fa-cart', $item->icon);
        $this->assertSame(IconHelper::TYPE_CLASS, $item->iconType());
        $this->assertSame('fa fa-cart', $item->iconClass());
        $this->assertNull($item->iconAssetId());
    }

    public function testAnAssetIconSavesAndExposesItsId(): void
    {
        $item = $this->urlItem();
        $item->icon = 'asset:42';

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
        $this->assertSame('asset:42', $item->icon);
        $this->assertSame(IconHelper::TYPE_ASSET, $item->iconType());
        $this->assertSame(42, $item->iconAssetId());
        $this->assertNull($item->iconClass());
    }

    public function testAnEmptyIconSavesAsNull(): void
    {
        $item = $this->urlItem();
        $item->icon = '   ';

        $this->assertTrue($item->validate(), json_encode($item->getErrors()));
        $this->assertNull($item->icon);
        $this->assertNull($item->iconType());
    }

    /**
     * The length rule and the grammar rule are independent — an over-long
     * but otherwise well-formed icon has to fail on the column width, not
     * pass because it parsed.
     */
    public function testAnOverLongIconIsStillRejected(): void
    {
        $item = $this->urlItem();
        $item->icon = str_repeat('a', 256);

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('icon', $item->getErrors());
    }

    // ---------------------------------------------------------------------
    // The CP form's three inputs → one column
    // ---------------------------------------------------------------------

    public function testTheIconSourceSelectDecidesWhichInputWins(): void
    {
        // Craft posts element selects as an array of ids.
        $this->assertSame('asset:42', IconHelper::composeFromForm('asset', 'icon-cart', ['42']));
        $this->assertSame('icon-cart', IconHelper::composeFromForm('class', 'icon-cart', ['42']));
        $this->assertSame('asset:42', IconHelper::composeFromForm('asset', '', '42'));
    }

    public function testChoosingNoIconClearsTheColumn(): void
    {
        $this->assertNull(IconHelper::composeFromForm('', 'icon-cart', ['42']));
        $this->assertNull(IconHelper::composeFromForm(null, 'icon-cart', ['42']));
    }

    public function testAnEmptyPickerOrBlankFieldIsNoIcon(): void
    {
        $this->assertNull(IconHelper::composeFromForm('asset', '', []));
        $this->assertNull(IconHelper::composeFromForm('asset', '', ['']));
        $this->assertNull(IconHelper::composeFromForm('asset', '', null));
        $this->assertNull(IconHelper::composeFromForm('class', '   ', []));
    }

    public function testAnUnsafeClassFieldSurvivesComposeAndIsRejectedByValidationInstead(): void
    {
        // composeFromForm() is not a sanitizer — it must not quietly drop or
        // mangle a bad value, or the editor would be told the save
        // succeeded with an icon they never chose. The model rejects it.
        $composed = IconHelper::composeFromForm('class', '<svg onload="alert(1)">', []);

        $this->assertNotNull($composed);

        $item = $this->urlItem();
        $item->icon = $composed;

        $this->assertFalse($item->validate());
        $this->assertArrayHasKey('icon', $item->getErrors());
    }

    // ---------------------------------------------------------------------
    // What Twig sees
    // ---------------------------------------------------------------------

    public function testANodeExposesTheIconThroughTypedAccessors(): void
    {
        $class = $this->nodeWithIcon('icon-cart');
        $this->assertTrue($class->hasIcon());
        $this->assertSame('class', $class->iconType());
        $this->assertSame('icon-cart', $class->iconClass());
        $this->assertNull($class->iconAssetId());

        $asset = $this->nodeWithIcon('asset:42');
        $this->assertTrue($asset->hasIcon());
        $this->assertSame('asset', $asset->iconType());
        $this->assertSame(42, $asset->iconAssetId());
        $this->assertNull($asset->iconClass());

        $none = $this->nodeWithIcon(null);
        $this->assertFalse($none->hasIcon());
        $this->assertNull($none->iconType());
    }

    /**
     * withChildren() clones the node for the per-request half of the
     * pipeline; the icon is readonly state that has to survive that copy.
     */
    public function testTheIconSurvivesTheNodeCopyMadeForVisibilityAndActiveState(): void
    {
        $node = $this->nodeWithIcon('asset:42');
        $copy = $node->withChildren([$this->nodeWithIcon('icon-cart')]);

        $this->assertSame('asset:42', $copy->icon);
        $this->assertSame(42, $copy->iconAssetId());
        $this->assertSame('icon-cart', $copy->children[0]->iconClass());
    }

    // ---------------------------------------------------------------------
    // The bundled macro
    // ---------------------------------------------------------------------

    public function testTheBundledMacroRendersIconsThroughTheSafeAccessorsOnly(): void
    {
        $macro = file_get_contents(__DIR__ . '/../../src/templates/_macros/tree.twig');

        $this->assertStringContainsString('{% macro icon(node) %}', $macro);
        // Never the raw column, and never unescaped: `node.icon` or a `raw`
        // filter anywhere near the icon would defeat the whole model.
        $this->assertStringContainsString('node.iconClass()', $macro);
        $this->assertStringNotContainsString('node.icon }}', $macro);
        $this->assertStringNotContainsString('node.icon|raw', $macro);
        // Asset icons go through <img src>, never inlined file contents.
        $this->assertStringContainsString('<img class="menu-builder-icon" src="{{ iconAsset.url }}"', $macro);
        // Decorative by default — the title is the accessible name.
        $this->assertStringContainsString('aria-hidden="true"', $macro);
        $this->assertStringContainsString('alt=""', $macro);
        // The link branch and the heading branch. Not the mega-menu trigger:
        // that button no longer repeats the item's label or its icon — it is
        // a caret with an accessible name of its own, sitting beside the
        // label the item already rendered.
        $this->assertSame(2, substr_count($macro, 'self.icon(node)'));
    }

    /**
     * The resolver hands the stored reference straight through to the node
     * — no per-request resolution baked into what gets cached, which is
     * what makes `craft.menuBuilder.iconAsset()` (and not a cached URL) the
     * right place to look an asset up.
     */
    public function testTheResolverPassesTheStoredIconThroughToTheNode(): void
    {
        $resolver = file_get_contents(__DIR__ . '/../../src/services/MenuBuilderResolver.php');

        $this->assertStringContainsString('icon: $item->icon,', $resolver);
    }

    /**
     * Duplicating one item and duplicating a whole menu are the same copy
     * — so "the icon survives duplication" is one fact, not two that can
     * drift apart. (Which columns that copy carries is pinned by
     * MenuBuilderItemLifecycleTest's persisted-property coverage.)
     */
    public function testItemAndMenuDuplicationShareOneCopyOfTheColumnList(): void
    {
        $items = file_get_contents(__DIR__ . '/../../src/services/MenuBuilderItemService.php');
        $groups = file_get_contents(__DIR__ . '/../../src/services/MenuBuilderGroupService.php');

        $this->assertStringContainsString('$record->icon = $item->icon;', $items);
        $this->assertStringContainsString('$clone->icon = $original->icon;', $items);
        $this->assertStringContainsString('$item->icon = $record->icon;', $items);
        // Menu duplication copies items only via duplicateAllForGroup() →
        // duplicateRecord(), the same clone the single-item path uses.
        $this->assertStringContainsString('items->duplicateAllForGroup(', $groups);
        $this->assertStringContainsString('$this->duplicateRecord($root, null, $targetGroupId', $items);
    }

    /**
     * The CP form has to post the three inputs composeFromForm() reads, and
     * ItemsController has to be the thing that composes them — a stray
     * `$item->icon = post('icon')` would bypass the source select entirely.
     */
    public function testTheCpFormPostsTheInputsTheControllerComposes(): void
    {
        $fields = file_get_contents(__DIR__ . '/../../src/templates/items/_fields.twig');

        foreach (["name: 'iconSource'", "name: 'icon'", "name: 'iconAsset'"] as $input) {
            $this->assertStringContainsString($input, $fields);
        }

        $this->assertStringContainsString('data-icon-class', $fields);
        $this->assertStringContainsString('data-icon-asset', $fields);

        $controller = file_get_contents(__DIR__ . '/../../src/controllers/ItemsController.php');

        $this->assertStringContainsString('IconHelper::composeFromForm(', $controller);
        $this->assertStringNotContainsString("\$item->icon = \$this->bodyString('icon')", $controller);
    }
}

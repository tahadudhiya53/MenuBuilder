<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * The dashboard's quick-add panel is the **only** way to create a menu item —
 * `ItemsController::actionEdit()` is edit-only and the
 * `menu-builder/<groupHandle>/items/new` route is gone (see ARCHITECTURE.md,
 * "Single path per behaviour"). That makes its type list load-bearing in a way
 * an ordinary form's isn't: a type missing from it is a type nobody can
 * create, even though the editor, the model, the resolver and the renderer all
 * support it.
 *
 * That is exactly what happened to `dynamic` — it was added to the full
 * editor's type list and not to quick-add's, so a dynamic item could be
 * configured, duplicated and rendered but never made in the first place.
 *
 * These tests scan the templates the same way CpAffordanceTest does, and are
 * written against `MenuBuilderItem::TYPES` rather than against `dynamic` alone,
 * so the next type added to the model is caught by the same guard.
 */
class QuickAddCreationTest extends TestCase
{
    private const TEMPLATE_DIR = __DIR__ . '/../../src/templates/';
    private const JS_DIR = __DIR__ . '/../../src/web/assets/cp/js/';

    // ---------------------------------------------------------------------
    // Every supported type is creatable
    // ---------------------------------------------------------------------

    /**
     * @dataProvider itemTypeProvider
     */
    public function testQuickAddOffersEverySupportedItemType(string $type): void
    {
        $this->assertContains(
            $type,
            $this->typeSelectValues('dashboard/index.twig'),
            "`$type` is a supported item type with no way to create it: quick-add is the only creation path."
        );
    }

    /**
     * @dataProvider itemTypeProvider
     */
    public function testTheFullEditorOffersEverySupportedItemType(string $type): void
    {
        $this->assertContains($type, $this->typeSelectValues('items/_fields.twig'));
    }

    /** Neither list may offer a type the model would reject. */
    public function testNeitherTypeListOffersAnUnknownType(): void
    {
        foreach (['dashboard/index.twig', 'items/_fields.twig'] as $template) {
            foreach ($this->typeSelectValues($template) as $value) {
                $this->assertContains($value, MenuBuilderItem::TYPES, "$template offers unknown type `$value`.");
            }
        }
    }

    /**
     * @return array<string,array{string}>
     */
    public static function itemTypeProvider(): array
    {
        return array_combine(
            MenuBuilderItem::TYPES,
            array_map(static fn(string $type): array => [$type], MenuBuilderItem::TYPES)
        );
    }

    // ---------------------------------------------------------------------
    // The dynamic type is actually configurable in quick-add
    // ---------------------------------------------------------------------

    /**
     * Offering `dynamic` in the type list without a source picker would just
     * move the dead end: `validateDynamicSource()` rejects a dynamic item that
     * carries no source, so the save could never succeed.
     */
    public function testQuickAddRendersASourceSectionForDynamicItems(): void
    {
        $source = $this->template('dashboard/index.twig');

        $this->assertStringContainsString('data-quick-add-section="dynamic"', $source);
    }

    /**
     * The two fields `validateDynamicSource()` requires, posted under the names
     * `buildMetadata()` already reads — not a second representation of a
     * dynamic source.
     */
    public function testQuickAddPostsTheFieldNamesTheControllerAlreadyReads(): void
    {
        $section = $this->quickAddDynamicSection();
        $buildMetadata = $this->methodSource(ItemsController::class, 'buildMetadata');

        foreach (['dynamicSourceType', 'dynamicSourceId'] as $field) {
            $this->assertStringContainsString("name: '$field'", $section, "Quick-add must post `$field`.");
            $this->assertStringContainsString("'$field'", $buildMetadata, "buildMetadata() must read `$field`.");
        }
    }

    /** One picker per source type the model accepts, keyed by the shared hook. */
    public function testQuickAddRendersOnePickerPerDynamicSourceType(): void
    {
        $section = $this->quickAddDynamicSection();

        foreach (MenuBuilderItem::DYNAMIC_SOURCE_TYPES as $sourceType) {
            $this->assertStringContainsString(
                'data-dynamic-source="' . $sourceType . '"',
                $section,
                "Quick-add is missing the `$sourceType` source picker."
            );
        }

        $this->assertSame(
            count(MenuBuilderItem::DYNAMIC_SOURCE_TYPES),
            substr_count($section, 'data-dynamic-source='),
            'Quick-add must render exactly one picker per source type.'
        );
    }

    /** The same `sourceType` values the model validates against, and no others. */
    public function testQuickAddOffersOnlyKnownDynamicSourceTypes(): void
    {
        preg_match_all("/value: '([a-z]+)' \}/", $this->quickAddSourceTypeSelect(), $matches);

        $this->assertSame(MenuBuilderItem::DYNAMIC_SOURCE_TYPES, $matches[1]);
    }

    /**
     * The editor must never ask someone to go and look an internal section,
     * category-group or volume ID up under Settings first — the pickers are
     * built from Craft's own service listings, the same way the full editor
     * builds them.
     */
    public function testQuickAddSourcePickersAreHumanReadable(): void
    {
        $section = $this->quickAddDynamicSection();

        foreach (['entries.getAllSections()', 'categories.getAllGroups()', 'volumes.getAllVolumes()'] as $listing) {
            $this->assertStringContainsString($listing, $section);
        }

        $this->assertStringNotContainsString(
            "type: 'number'",
            $section,
            'The source must be picked from a list, not typed in as a raw ID.'
        );
    }

    // ---------------------------------------------------------------------
    // Only the active picker may post
    // ---------------------------------------------------------------------

    /**
     * All three pickers post `dynamicSourceId`, so exactly one may ever be
     * enabled — a disabled field is excluded from both `serializeArray()` and
     * a native submission. This is the same one-name-many-sections rule
     * `customUrl` (url/anchor) and `elementId` (entry/category/asset) follow.
     */
    public function testTheDynamicSourcePickersShareOneFieldName(): void
    {
        $this->assertSame(
            count(MenuBuilderItem::DYNAMIC_SOURCE_TYPES),
            substr_count($this->quickAddDynamicSection(), "name: 'dynamicSourceId'")
        );
    }

    /**
     * ...and both screens ask the *same* function which one that is. A second
     * implementation of "which picker may post" is the hazard the removed
     * drop-onto-a-row gesture used to carry for depth admissibility.
     */
    public function testBothScreensShareOneDynamicSourceToggle(): void
    {
        $itemFields = file_get_contents(self::JS_DIR . 'item-fields.js');

        $this->assertSame(
            1,
            substr_count($itemFields, 'window.MenuBuilder.syncDynamicSourcePickers = function'),
            'syncDynamicSourcePickers() must be defined exactly once.'
        );

        $this->assertStringContainsString(
            'window.MenuBuilder.syncDynamicSourcePickers(',
            $itemFields,
            'The full editor must use the shared toggle.'
        );
        $this->assertStringContainsString(
            'window.MenuBuilder.syncDynamicSourcePickers(',
            $this->template('dashboard/index.twig'),
            'Quick-add must use the shared toggle rather than its own.'
        );
    }

    /** Inactive sections are disabled through one shared helper too. */
    public function testInactiveQuickAddSectionsAreDisabledThroughTheSharedHelper(): void
    {
        $this->assertStringContainsString(
            'window.MenuBuilder.setFieldsDisabled(section, !visible)',
            $this->template('dashboard/index.twig')
        );
    }

    /**
     * The dynamic section is re-enabled wholesale by the type loop, so the
     * picker sync has to run after it — otherwise all three pickers would post.
     */
    public function testTheDynamicPickerSyncRunsAfterTheSectionLoop(): void
    {
        $source = $this->template('dashboard/index.twig');
        $loop = strpos($source, 'window.MenuBuilder.setFieldsDisabled(section, !visible)');
        $sync = strpos($source, 'updateDynamicSource();');

        $this->assertNotFalse($loop);
        $this->assertNotFalse($sync);
        $this->assertGreaterThan($loop, $sync);
    }

    // ---------------------------------------------------------------------
    // Nothing else about the creation path moved
    // ---------------------------------------------------------------------

    /** Quick-add must stay a real form, so a no-JS post still creates an item. */
    public function testQuickAddRemainsANativeForm(): void
    {
        $source = $this->template('dashboard/index.twig');

        $this->assertMatchesRegularExpression('/<form method="post"[^>]*id="menu-builder-quick-add-form"/', $source);
        $this->assertStringContainsString('name="action" value="menu-builder/items/save"', $source);
        $this->assertStringContainsString('csrfInput()', $source);
        $this->assertStringContainsString("redirectInput('menu-builder/' ~ group.handle)", $source);
    }

    /** No second creation route came back with the fix. */
    public function testThereIsStillNoSeparateNewItemRoute(): void
    {
        $plugin = file_get_contents(__DIR__ . '/../../src/MenuBuilder.php');

        $this->assertStringNotContainsString('items/new', $plugin);
        $this->assertStringNotContainsString(
            'items/new',
            $this->methodSource(ItemsController::class, 'actionEdit')
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function template(string $path): string
    {
        return file_get_contents(self::TEMPLATE_DIR . $path);
    }

    /**
     * The `value:` entries of the `type` select in a template — i.e. the item
     * types that screen actually lets someone pick.
     *
     * @return string[]
     */
    private function typeSelectValues(string $template): array
    {
        $source = $this->template($template);
        $start = strpos($source, "name: 'type',");

        $this->assertNotFalse($start, "No type select found in $template.");

        $options = substr($source, $start, strpos($source, '],', $start) - $start);
        preg_match_all("/value: '([a-z]+)' \}/", $options, $matches);

        return $matches[1];
    }

    private function quickAddDynamicSection(): string
    {
        $source = $this->template('dashboard/index.twig');
        $start = strpos($source, 'data-quick-add-section="dynamic"');

        $this->assertNotFalse($start, 'Quick-add has no dynamic section.');

        $end = strpos($source, '</div>' . PHP_EOL . '                </div>', $start);

        return substr($source, $start, ($end === false ? strlen($source) : $end) - $start);
    }

    private function quickAddSourceTypeSelect(): string
    {
        $section = $this->quickAddDynamicSection();
        $start = strpos($section, "name: 'dynamicSourceType',");

        $this->assertNotFalse($start, 'Quick-add has no dynamic source-type select.');

        return substr($section, $start, strpos($section, '],', $start) - $start);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}

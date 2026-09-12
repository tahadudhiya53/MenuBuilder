<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tahadudhiya\MenuBuilder\controllers\DashboardController;
use Tahadudhiya\MenuBuilder\controllers\GroupsController;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;
use Tahadudhiya\MenuBuilder\controllers\PreviewController;
use Tahadudhiya\MenuBuilder\helpers\DateValidationHelper;
use Tahadudhiya\MenuBuilder\helpers\LinkAttributeHelper;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderApiHelper;
use Tahadudhiya\MenuBuilder\models\IconAccessors;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;
use Tahadudhiya\MenuBuilder\models\MenuBuilderMegaMenuConfig;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;

/**
 * The pieces that exist because two or more layers had the same rule written
 * out twice. Each one used to be a pair of copies that could drift; these
 * tests pin the behaviour of the single implementation *and*, where a copy
 * would be invisible to behaviour alone, that there is still only one of it.
 *
 * Nothing here is a new rule. Every assertion states what both copies already
 * did, so a regression in the consolidation shows up as a failure rather than
 * as a menu that renders slightly differently on one of two screens.
 */
class MenuBuilderSharedCodeTest extends TestCase
{
    // ---------------------------------------------------------------------
    // LinkAttributeHelper::htmlAttributeErrors()
    //
    // The item and the group both hold an `htmlAttributes` bag that ends up
    // on markup, so both need the same answer for "not a bag at all" as well
    // as for each attribute in one.
    // ---------------------------------------------------------------------

    public function testANonArrayAttributeBagIsOneInvalidBagError(): void
    {
        $this->assertSame(['Invalid attributes.'], LinkAttributeHelper::htmlAttributeErrors('class="x"'));
        $this->assertSame(['Invalid attributes.'], LinkAttributeHelper::htmlAttributeErrors(null));
        $this->assertSame(['Invalid attributes.'], LinkAttributeHelper::htmlAttributeErrors(42));
    }

    public function testASafeAttributeBagHasNoErrors(): void
    {
        $this->assertSame([], LinkAttributeHelper::htmlAttributeErrors([]));
        $this->assertSame([], LinkAttributeHelper::htmlAttributeErrors(['data-id' => '5']));
    }

    public function testAnUnsafeAttributeBagReportsTheSameErrorsAsTheStrictChecker(): void
    {
        $bag = ['onclick' => 'x()', 'href' => 'javascript:alert(1)'];

        $this->assertSame(
            LinkAttributeHelper::validateHtmlAttributes($bag),
            LinkAttributeHelper::htmlAttributeErrors($bag)
        );
        $this->assertNotEmpty(LinkAttributeHelper::htmlAttributeErrors($bag));
    }

    /**
     * @dataProvider attributeBagProvider
     * @param mixed $bag
     */
    public function testTheItemAndTheGroupRejectExactlyTheSameBags(mixed $bag): void
    {
        $item = new MenuBuilderItem();
        $item->htmlAttributes = $bag;
        $item->validateHtmlAttributes();

        $group = new MenuBuilderGroup();
        $group->htmlAttributes = $bag;
        $group->validateHtmlAttributes();

        $this->assertSame(
            $item->getErrors('htmlAttributes'),
            $group->getErrors('htmlAttributes'),
            'The two models must agree about what an unsafe attribute bag is.'
        );
    }

    /**
     * @return array<string,array{mixed}>
     */
    public static function attributeBagProvider(): array
    {
        return [
            // The property is typed `array`, so "not a bag at all" is only
            // reachable through the helper itself — see the direct test above.
            'empty' => [[]],
            'safe' => [['data-id' => '5', 'role' => 'menuitem']],
            'event handler' => [['onclick' => 'alert(1)']],
            'executing scheme' => [['href' => 'java\tscript:alert(1)']],
            'invalid name' => [['not a name' => 'x']],
        ];
    }

    // ---------------------------------------------------------------------
    // DateValidationHelper::parseOrNull()
    //
    // Save-time validation and evaluation-time DateRangeRule read a
    // `dateRange` bound through this one reader, so what a save accepts and
    // what an evaluation honours cannot disagree.
    // ---------------------------------------------------------------------

    public function testParseOrNullReadsAWellFormedBound(): void
    {
        $date = DateValidationHelper::parseOrNull('2026-09-01 09:00');

        $this->assertNotNull($date);
        $this->assertSame('2026-09-01 09:00', $date->format('Y-m-d H:i'));
    }

    public function testParseOrNullHonoursTheGivenTimezone(): void
    {
        $date = DateValidationHelper::parseOrNull('2026-09-01 09:00', new DateTimeZone('Europe/Amsterdam'));

        $this->assertNotNull($date);
        $this->assertSame('Europe/Amsterdam', $date->getTimezone()->getName());
    }

    public function testParseOrNullFailsClosedForEveryUnusableBound(): void
    {
        // The whole point of the shared reader: an out-of-range calendar date
        // is refused rather than silently shifted into the next month.
        $this->assertNull(DateValidationHelper::parseOrNull('2026-02-30'));
        $this->assertNull(DateValidationHelper::parseOrNull('not a date'));
        $this->assertNull(DateValidationHelper::parseOrNull(''));
        $this->assertNull(DateValidationHelper::parseOrNull('   '));
        $this->assertNull(DateValidationHelper::parseOrNull(null));
        $this->assertNull(DateValidationHelper::parseOrNull(true));
        $this->assertNull(DateValidationHelper::parseOrNull(['2026-01-01']));
        $this->assertNull(DateValidationHelper::parseOrNull(20260101));
    }

    /** A rule whose start is an impossible date is rejected on save, not shifted. */
    public function testAnImpossibleVisibilityDateStillFailsValidation(): void
    {
        $item = new MenuBuilderItem();
        $item->visibility = [['type' => 'dateRange', 'start' => '2026-02-30']];
        $item->validateVisibility();

        $this->assertNotEmpty($item->getErrors('visibility'));
    }

    // ---------------------------------------------------------------------
    // MenuBuilderMegaMenuConfig's column bounds
    //
    // Four places used to write "between 1 and 6" out for themselves: the
    // controller (twice), the item validator and the resolver.
    // ---------------------------------------------------------------------

    public function testClampColumnsBringsAPostedCountInsideTheBounds(): void
    {
        $this->assertSame(1, MenuBuilderMegaMenuConfig::clampColumns(0));
        $this->assertSame(1, MenuBuilderMegaMenuConfig::clampColumns(-3));
        $this->assertSame(3, MenuBuilderMegaMenuConfig::clampColumns(3));
        $this->assertSame(6, MenuBuilderMegaMenuConfig::clampColumns(6));
        $this->assertSame(6, MenuBuilderMegaMenuConfig::clampColumns(99));
    }

    public function testIsValidColumnsAcceptsOnlyIntsInsideTheBounds(): void
    {
        $this->assertTrue(MenuBuilderMegaMenuConfig::isValidColumns(1));
        $this->assertTrue(MenuBuilderMegaMenuConfig::isValidColumns(6));
        $this->assertFalse(MenuBuilderMegaMenuConfig::isValidColumns(0));
        $this->assertFalse(MenuBuilderMegaMenuConfig::isValidColumns(7));
        // Strict about the type: a string or a bool means the value did not
        // come through the form, so it is reported rather than cast.
        $this->assertFalse(MenuBuilderMegaMenuConfig::isValidColumns('3'));
        $this->assertFalse(MenuBuilderMegaMenuConfig::isValidColumns(true));
        $this->assertFalse(MenuBuilderMegaMenuConfig::isValidColumns(3.0));
        $this->assertFalse(MenuBuilderMegaMenuConfig::isValidColumns(null));
    }

    /** Whatever the clamp produces, the validator must accept — they are two halves of one rule. */
    public function testEveryClampedColumnCountPassesValidation(): void
    {
        foreach ([-5, 0, 1, 2, 6, 7, 500] as $posted) {
            $this->assertTrue(
                MenuBuilderMegaMenuConfig::isValidColumns(MenuBuilderMegaMenuConfig::clampColumns($posted)),
                "A clamped $posted must be a legal column count."
            );
        }
    }

    public function testTheItemValidatorUsesTheSameBoundsAsTheClamp(): void
    {
        foreach ([['columns' => 0], ['columns' => 7], ['columns' => '3']] as $megaMenu) {
            $item = new MenuBuilderItem();
            $item->metadata = ['megaMenu' => ['enabled' => true] + $megaMenu];
            $item->validateMegaMenu();

            $this->assertNotEmpty($item->getErrors('metadata'));
        }

        $ok = new MenuBuilderItem();
        $ok->metadata = ['megaMenu' => ['enabled' => true, 'columns' => 4], 'megaMenuColumn' => 2];
        $ok->validateMegaMenu();

        $this->assertSame([], $ok->getErrors('metadata'));
    }

    /** The bounds are stated once — no other file writes the numbers out. */
    public function testTheColumnBoundsAreNotWrittenOutAnywhereElse(): void
    {
        foreach (['src/controllers/ItemsController.php', 'src/services/MenuBuilderResolver.php'] as $path) {
            $source = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);

            $this->assertStringNotContainsString('min(6', $source, "$path re-states the column ceiling.");
            $this->assertStringContainsString('MenuBuilderMegaMenuConfig::', $source);
        }
    }

    // ---------------------------------------------------------------------
    // IconAccessors
    //
    // The persisted item and the resolved node carry the same `icon` column
    // and must read it the same way.
    // ---------------------------------------------------------------------

    public function testTheItemAndTheNodeShareOneIconReader(): void
    {
        $this->assertContains(IconAccessors::class, class_uses(MenuBuilderItem::class));
        $this->assertContains(IconAccessors::class, class_uses(MenuBuilderNode::class));
    }

    /**
     * @dataProvider iconProvider
     */
    public function testTheItemAndTheNodeReadEveryStoredIconIdentically(?string $stored): void
    {
        $item = new MenuBuilderItem();
        $item->icon = $stored;
        $node = self::node($stored);

        $this->assertSame($item->iconType(), $node->iconType());
        $this->assertSame($item->iconClass(), $node->iconClass());
        $this->assertSame($item->iconAssetId(), $node->iconAssetId());
        $this->assertSame($item->hasIcon(), $node->hasIcon());
    }

    /**
     * @return array<string,array{?string}>
     */
    public static function iconProvider(): array
    {
        return [
            'none' => [null],
            'blank' => [''],
            'class' => ['icon-cart'],
            'class list' => ['fa fa-cart'],
            'asset' => ['asset:123'],
            'asset zero' => ['asset:0'],
            // Fails closed in both: markup is never a class value.
            'markup' => ['<svg onload="alert(1)">'],
            'javascript scheme' => ['javascript:alert(1)'],
        ];
    }

    // ---------------------------------------------------------------------
    // MenuBuilderApiHelper's one normalizer map
    //
    // "Is this parameter usable?" and "what does it mean?" are answered by
    // the same mapping, so the REST surface cannot validate one set of
    // parameters and resolve another.
    // ---------------------------------------------------------------------

    public function testEveryRecognisedParamIsBothValidatedAndResolved(): void
    {
        $usable = [
            'site' => 'default',
            'siteId' => '1',
            'currentUri' => 'about/team',
            'viewport' => 'mobile',
        ];

        $this->assertSame(
            MenuBuilderApiHelper::PARAMS,
            array_keys($usable),
            'A new recognised parameter needs a case in this test as well as in the map.'
        );
        $this->assertNull(MenuBuilderApiHelper::invalidParam($usable));
        $this->assertSame(array_keys($usable), array_keys(MenuBuilderApiHelper::arguments($usable)));
    }

    /**
     * @dataProvider unusableParamProvider
     */
    public function testAParamTheArgumentsCannotResolveIsAlsoReportedAsInvalid(string $param, mixed $value): void
    {
        $params = [$param => $value];

        $this->assertSame($param, MenuBuilderApiHelper::invalidParam($params));
        $this->assertNull(MenuBuilderApiHelper::arguments($params)[$param]);
    }

    /**
     * @return array<string,array{string,mixed}>
     */
    public static function unusableParamProvider(): array
    {
        return [
            'blank site' => ['site', ''],
            'site with punctuation' => ['site', 'not a handle'],
            'site id zero' => ['siteId', '0'],
            'site id not a number' => ['siteId', 'abc'],
            'blank uri' => ['currentUri', ''],
            'viewport typo' => ['viewport', 'mobil'],
        ];
    }

    public function testAnUnrecognisedParamNeverReachesTheArguments(): void
    {
        $this->assertNull(MenuBuilderApiHelper::invalidParam(['limit' => '5']));
        $this->assertSame([], MenuBuilderApiHelper::arguments(['limit' => '5']));
    }

    // ---------------------------------------------------------------------
    // The CP controllers' shared base
    // ---------------------------------------------------------------------

    /**
     * The helpers the base controller owns exist there and nowhere else. A
     * controller that grew its own copy of one of them is how the CP's two
     * mutation-response paths (JSON, and flash-plus-redirect) drifted apart
     * before, and the copy is invisible to behavioural tests.
     */
    public function testNoControllerReimplementsASharedBaseHelper(): void
    {
        $shared = [
            'respondToMutation',
            'bodyIntOrNull',
            'bodyString',
            'bodyArray',
            'groupByHandleOrRedirect',
            'permissionDeniedMessage',
            'currentUserAffordances',
        ];

        $controllers = [
            GroupsController::class,
            ItemsController::class,
            DashboardController::class,
            PreviewController::class,
        ];

        foreach ($controllers as $controller) {
            $declared = array_map(
                static fn(\ReflectionMethod $method): string => $method->getName(),
                (new ReflectionClass($controller))->getMethods()
            );

            foreach ($shared as $method) {
                $this->assertNotContains(
                    $method,
                    array_filter(
                        $declared,
                        static fn(string $name): bool => $name === $method
                            && (new \ReflectionMethod($controller, $name))->getDeclaringClass()->getName() === $controller
                    ),
                    "$controller re-declares $method() instead of using the shared base."
                );
            }
        }
    }

    /** Each read-only screen states only the wording it differs by. */
    public function testTheReadOnlyScreensShareTheSameDeniedWording(): void
    {
        $message = static function(string $controller): string {
            $constant = (new ReflectionClass($controller))->getReflectionConstant('PERMISSION_DENIED_MESSAGE');

            return (string)$constant->getValue();
        };

        $this->assertSame($message(DashboardController::class), $message(PreviewController::class));
        $this->assertNotSame($message(DashboardController::class), $message(GroupsController::class));
    }

    /**
     * `respondToMutation()` takes its destination as a URL, and every caller
     * gives it one.
     *
     * `redirect()` is not a factory: it stamps `Location` and a 302 onto the
     * shared `Craft::$app->response` and returns that same object. PHP
     * evaluates arguments before the call, so a `$this->redirect(...)` passed
     * in was applied on the JSON path too — where `asJson()` sets only
     * `format` and `data`, leaving the CP's axios a 302 it rejects. A menu
     * was deleted and the list still said it couldn't be.
     */
    public function testTheMutationResponderIsNeverHandedABuiltRedirect(): void
    {
        $parameters = (new \ReflectionMethod(GroupsController::class, 'respondToMutation'))->getParameters();
        $destination = end($parameters);

        $this->assertSame('redirectUrl', $destination->getName());
        $this->assertSame('?string', (string)$destination->getType(), 'A destination must be a URL, not a built response.');

        foreach ([GroupsController::class, ItemsController::class] as $controller) {
            $this->assertDoesNotMatchRegularExpression(
                '/respondToMutation\((?:[^;]*?)\$this->redirect\(/s',
                self::source($controller),
                "$controller builds a redirect while calling respondToMutation(), which applies it on the JSON path too."
            );
        }
    }

    // ---------------------------------------------------------------------
    // The CP macros
    // ---------------------------------------------------------------------

    /**
     * The quick-add panel and the full item editor render the same closed
     * lists — link types, dynamic source types, the three source pickers —
     * so neither may carry its own copy any more.
     */
    public function testTheTwoItemFormsRenderTheSharedControls(): void
    {
        $quickAdd = self::template('dashboard/index.twig');
        $editor = self::template('items/_fields.twig');

        foreach ([$quickAdd, $editor] as $form) {
            $this->assertStringContainsString('{% import "menu-builder/_macros/cp" as cp %}', $form);
            $this->assertStringContainsString('cp.linkTypeField(', $form);
            $this->assertStringContainsString('cp.dynamicSourceTypeField(', $form);
            $this->assertStringContainsString('cp.dynamicSourcePickers(', $form);
            $this->assertStringContainsString('cp.elementPickerField(', $form);

            // The option lists themselves live in the macro only.
            $this->assertStringNotContainsString("value: 'nonclickable'", $form);
            $this->assertStringNotContainsString('entries.getAllSections()', $form);
            $this->assertStringNotContainsString('categories.getAllGroups()', $form);
            $this->assertStringNotContainsString('volumes.getAllVolumes()', $form);
        }
    }

    /**
     * The ids both screens' JavaScript and labels rely on are unchanged by
     * the move into a macro — `#quick-add-dynamic-source-type` and
     * `#dynamicSourceType` are both read by name.
     */
    public function testTheDynamicSourceIdsTheScriptsReadAreStillRendered(): void
    {
        $quickAdd = self::template('dashboard/index.twig');
        $editor = self::template('items/_fields.twig');

        foreach (['quick-add-dynamic-source-type', 'quick-add-dynamic-source-entries', 'quick-add-dynamic-source-categories', 'quick-add-dynamic-source-assets'] as $id) {
            $this->assertStringContainsString($id, $quickAdd);
        }

        foreach (['dynamicSourceType', 'dynamicSourceIdEntries', 'dynamicSourceIdCategories', 'dynamicSourceIdAssets'] as $id) {
            $this->assertStringContainsString($id, $editor);
        }
    }

    /** Every dynamic-source picker keeps the wrapper hook item-fields.js switches on. */
    public function testTheMacroKeepsTheDataDynamicSourceHook(): void
    {
        $macro = self::template('_macros/cp.twig');

        $this->assertStringContainsString('data-dynamic-source="{{ source.key }}"', $macro);
        $this->assertStringContainsString("name: 'dynamicSourceId'", $macro);
        // One picker enabled at a time, decided before any script runs.
        $this->assertStringContainsString('disabled: activeSourceType is not null', $macro);
    }

    /**
     * The tree partials get their affordances from one context, so the three
     * includes that used to list them each cannot hand a different set down.
     */
    public function testTheTreePartialsShareOneIncludeContext(): void
    {
        $index = self::template('dashboard/index.twig');

        $this->assertStringContainsString('{% set treeContext = {', $index);

        foreach (['dashboard/index.twig', 'dashboard/_branch.twig', 'dashboard/_items.twig'] as $path) {
            $source = self::template($path);

            if (!str_contains($source, '{% include "menu-builder/dashboard/_')) {
                continue;
            }

            $this->assertStringContainsString('treeContext|merge({', $source, "$path must merge the shared tree context.");

            // At most one mention: the definition in index.twig. An include
            // that listed the affordances again would make it two.
            $this->assertLessThanOrEqual(
                1,
                substr_count($source, 'canDelete: canDelete,'),
                "$path re-lists the tree context at an include."
            );
        }
    }

    /** Both sidebars are the same list of menus, differing only in where each entry links. */
    public function testBothScreensRenderTheSharedSidebar(): void
    {
        $this->assertStringContainsString("cp.menuSidebar(groups, group, '', true)", self::template('dashboard/index.twig'));
        $this->assertStringContainsString("cp.menuSidebar(groups, group, '/preview')", self::template('preview/index.twig'));
    }

    /**
     * The shared sidebar marks the current menu with a real attribute.
     *
     * Written as a ternary *inside* the tag, `aria-current="page"` goes
     * through the autoescaper and reaches the browser as
     * `aria-current=&quot;page&quot;` — not an attribute at all, so the
     * current menu is unannounced and the CSS that styles it never matches.
     * Every other `aria-current` in this plugin is already a `{% if %}`.
     */
    public function testTheSidebarMacroEmitsARealAriaCurrentAttribute(): void
    {
        $macros = self::template('_macros/cp.twig');

        $this->assertStringContainsString('{% if isCurrent %}aria-current="page"{% endif %}', $macros);
        $this->assertStringNotContainsString("? 'aria-current", $macros);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private static function template(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/' . $path);
    }

    private static function source(string $class): string
    {
        return (string)file_get_contents((string)(new ReflectionClass($class))->getFileName());
    }

    private static function node(?string $icon): MenuBuilderNode
    {
        return new MenuBuilderNode(
            id: 1,
            handle: 'home',
            type: MenuBuilderItem::TYPE_URL,
            title: 'Home',
            url: '/',
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
            level: 1,
        );
    }
}

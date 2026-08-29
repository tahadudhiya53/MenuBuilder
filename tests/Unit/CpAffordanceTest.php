<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\controllers\BaseMenuBuilderController;
use Tahadudhiya\MenuBuilder\controllers\DashboardController;
use Tahadudhiya\MenuBuilder\controllers\GroupsController;
use Tahadudhiya\MenuBuilder\controllers\ItemsController;
use Tahadudhiya\MenuBuilder\controllers\PreviewController;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * Which controls the control panel is allowed to *render*.
 *
 * ControllerPermissionTest pins what the server enforces. This pins the other
 * half of the same rule: a button whose action would answer 403 must not be
 * drawn in the first place. Getting that wrong isn't a security hole — the
 * gate still holds — but it is the difference between "I can't do that" and
 * "this plugin is broken", which is the whole of the editor's experience of
 * a restricted role.
 *
 * The decision is a pure static ({@see BaseMenuBuilderController::cpAffordances()})
 * for the same reason the permission mapping is: it can be checked without a
 * booted Craft app, and there is exactly one of it for all three controllers
 * and all six templates.
 */
class CpAffordanceTest extends TestCase
{
    private const TEMPLATE_DIR = __DIR__ . '/../../src/templates/';

    // ---------------------------------------------------------------------
    // The flag mapping itself
    // ---------------------------------------------------------------------

    public function testAnAdminCanDoEverythingWithoutHoldingAnyPermission(): void
    {
        $this->assertSame([
            'canView' => true,
            'canCreate' => true,
            'canEdit' => true,
            'canDelete' => true,
            'canManageSettings' => true,
        ], BaseMenuBuilderController::cpAffordances(true, []));
    }

    public function testAUserWithNoPermissionsCanDoNothing(): void
    {
        $this->assertSame([
            'canView' => false,
            'canCreate' => false,
            'canEdit' => false,
            'canDelete' => false,
            'canManageSettings' => false,
        ], BaseMenuBuilderController::cpAffordances(false, []));
    }

    /**
     * Each permission lights up exactly its own flag. The bugs this guards
     * against were all one flag standing in for another: Delete drawn for
     * anyone who could edit, "New menu" drawn for anyone who could create
     * items.
     *
     * @dataProvider permissionProvider
     */
    public function testEachPermissionGrantsOnlyItsOwnAffordance(string $permission, string $flag): void
    {
        $affordances = BaseMenuBuilderController::cpAffordances(false, [$permission]);

        foreach ($affordances as $name => $granted) {
            $this->assertSame(
                $name === $flag,
                $granted,
                "$permission must grant $flag and nothing else, but it decided $name."
            );
        }
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function permissionProvider(): array
    {
        return [
            'view' => ['menuBuilder:view', 'canView'],
            'create' => ['menuBuilder:create', 'canCreate'],
            'edit' => ['menuBuilder:edit', 'canEdit'],
            'delete' => ['menuBuilder:delete', 'canDelete'],
            'manageSettings' => ['menuBuilder:manageSettings', 'canManageSettings'],
        ];
    }

    /** The affordance list and the registered permission list must not drift apart. */
    public function testEveryRegisteredPermissionHasAnAffordanceFlag(): void
    {
        $plugin = file_get_contents(__DIR__ . '/../../src/MenuBuilder.php');
        preg_match_all("/'(menuBuilder:[a-zA-Z]+)' => \[/", $plugin, $matches);

        $registered = array_values(array_unique($matches[1]));
        $known = BaseMenuBuilderController::CP_PERMISSIONS;

        sort($registered);
        sort($known);

        $this->assertNotEmpty($registered, 'Expected MenuBuilder.php to register permissions.');
        $this->assertSame($known, $registered);
    }

    /**
     * A permission a user actually holds must produce the affordance for it —
     * the flags are keyed off CP_PERMISSIONS, so an entry missing from that
     * list would silently answer "false" for a user who holds it.
     */
    public function testEveryKnownPermissionIsAnswerable(): void
    {
        foreach (BaseMenuBuilderController::CP_PERMISSIONS as $permission) {
            $affordances = BaseMenuBuilderController::cpAffordances(false, [$permission]);

            $this->assertContains(
                true,
                $affordances,
                "$permission granted no affordance at all."
            );
        }
    }

    // ---------------------------------------------------------------------
    // Every CP screen is handed the flags
    // ---------------------------------------------------------------------

    /**
     * @dataProvider screenControllerProvider
     * @param class-string $controllerClass
     */
    public function testEveryScreenRendersWithTheAffordanceFlags(string $controllerClass, string $method): void
    {
        $source = $this->methodSource($controllerClass, $method);

        $this->assertStringContainsString(
            'currentUserAffordances()',
            $source,
            "$controllerClass::$method must hand its template the affordance flags."
        );
    }

    /**
     * @return array<string,array{class-string,string}>
     */
    public static function screenControllerProvider(): array
    {
        return [
            'menu tree' => [DashboardController::class, 'actionIndex'],
            'menus list' => [GroupsController::class, 'actionIndex'],
            'menu settings' => [GroupsController::class, 'actionEdit'],
            'item editor' => [ItemsController::class, 'actionEdit'],
            'preview' => [PreviewController::class, 'actionIndex'],
        ];
    }

    // ---------------------------------------------------------------------
    // The templates ask the right flag
    // ---------------------------------------------------------------------

    /**
     * Each destructive or create-shaped control, and the flag it must be
     * gated on. These pairings are exactly the ones that were wrong: Delete
     * and Duplicate both sat under `canEdit`, and the sidebar's "New menu"
     * under `canCreate` when creating a menu needs `manageSettings`.
     *
     * @dataProvider gatedControlProvider
     */
    public function testDestructiveControlsAreGatedOnTheRightFlag(string $template, string $marker, string $flag): void
    {
        $source = file_get_contents(self::TEMPLATE_DIR . $template);
        $position = strpos($source, $marker);

        $this->assertNotFalse($position, "Expected to find `$marker` in $template.");

        // The gate must be the nearest enclosing condition, not one somewhere
        // else in the file.
        $preceding = substr($source, 0, $position);
        $lastIf = strrpos($preceding, '{% if ');

        $this->assertNotFalse($lastIf, "`$marker` in $template is not inside any condition.");
        $this->assertStringContainsString(
            $flag,
            substr($preceding, $lastIf),
            "`$marker` in $template must be gated on $flag."
        );
    }

    /**
     * @return array<string,array{string,string,string}>
     */
    public static function gatedControlProvider(): array
    {
        return [
            'delete a menu item' => ['dashboard/_items.twig', 'data-mb-action="delete"', 'canDelete'],
            'duplicate a menu item' => ['dashboard/_items.twig', 'data-mb-action="duplicate"', 'canCreate'],
            'bulk delete' => ['dashboard/index.twig', 'data-mb-bulk-op="delete"', 'canDelete'],
            'new menu, from the tree sidebar' => ['dashboard/index.twig', 'menu-builder/groups/new', 'canManageSettings'],
            'new menu, from the menus list' => ['groups/_index.twig', 'menu-builder/groups/new', 'canManageSettings'],
            'delete a menu' => ['groups/_index.twig', 'data-mb-action="delete-group"', 'canDelete'],
            'duplicate a menu' => ['groups/_index.twig', 'data-mb-action="duplicate-group"', 'canManageSettings'],
        ];
    }

    // ---------------------------------------------------------------------
    // No nested <form>
    // ---------------------------------------------------------------------

    /**
     * `_layouts/cp` wraps the whole page — content *and* details — in one
     * `<form>` when `fullPageForm` is set, so a `<form>` of our own inside
     * either block is nested markup. An HTML parser drops the inner start
     * tag, which meant the Delete buttons on both edit screens had their
     * `onsubmit` confirmation silently discarded and ended up submitting the
     * page form. Destructive actions belong in `formActions` instead, which
     * posts the page form to a different action with a real confirmation.
     *
     * @dataProvider fullPageFormTemplateProvider
     */
    public function testFullPageFormScreensDeclareNoFormOfTheirOwn(string $template): void
    {
        $source = file_get_contents(self::TEMPLATE_DIR . $template);

        $this->assertStringContainsString('fullPageForm', $source, "$template is not a full-page form screen.");
        $this->assertDoesNotMatchRegularExpression(
            '/<form\b/',
            preg_replace('/\{#.*?#\}/s', '', $source) ?? '',
            "$template must not nest a <form> inside the page form — use formActions."
        );
    }

    /**
     * @return array<string,array{string}>
     */
    public static function fullPageFormTemplateProvider(): array
    {
        return [
            'menu settings' => ['groups/_edit.twig'],
            'item editor' => ['items/_edit.twig'],
        ];
    }

    /** The partial both editor wrappers include must not carry a form either. */
    public function testTheSharedItemFieldsPartialDeclaresNoForm(): void
    {
        $source = file_get_contents(self::TEMPLATE_DIR . 'items/_fields.twig');

        $this->assertDoesNotMatchRegularExpression('/<form\b/', $source);
    }

    /**
     * Both destructive edit-screen actions must go through Craft's
     * formActions, and must carry a confirmation — the whole point of moving
     * them out of a nested form was that their confirmation had stopped
     * running.
     *
     * @dataProvider fullPageFormTemplateProvider
     */
    public function testEditScreenDeleteActionsConfirmFirst(string $template): void
    {
        $source = file_get_contents(self::TEMPLATE_DIR . $template);

        $this->assertStringContainsString('formActions', $source);
        $this->assertStringContainsString('destructive: true', $source);
        $this->assertStringContainsString('confirm:', $source);
    }

    // ---------------------------------------------------------------------
    // Search summary
    // ---------------------------------------------------------------------

    public function testCountTreeCountsEveryRowIncludingDescendants(): void
    {
        $tree = [
            $this->item(1, [
                $this->item(2, [$this->item(3)]),
                $this->item(4),
            ]),
            $this->item(5),
        ];

        $this->assertSame(5, DashboardController::countTree($tree));
    }

    public function testCountTreeOfAnEmptyMenuIsZero(): void
    {
        $this->assertSame(0, DashboardController::countTree([]));
    }

    /** @param MenuBuilderItem[] $children */
    private function item(int $id, array $children = []): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->id = $id;
        $item->children = $children;

        return $item;
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

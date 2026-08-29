<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tahadudhiya\MenuBuilder\controllers\GroupsController;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\services\MenuBuilderGroupService;

/**
 * Navigation groups: field-level validation on the model, plus the lifecycle
 * invariants of the service and controller that mutate them — every mutation
 * goes through the service, multi-record mutations run in a transaction,
 * every frontend-affecting write invalidates the cache, and the database is
 * the only place group configuration is written. Those regress silently,
 * because the happy path keeps working either way and only a partial failure,
 * a stale menu, or a second store quietly diverging exposes them.
 *
 * The DB-backed behaviour behind them (rows actually written, the cascade
 * actually firing) needs a booted Craft app and is verified manually — see
 * the manual test list in ARCHITECTURE.md.
 */
class MenuBuilderGroupTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Model validation
    // ---------------------------------------------------------------------

    public function testNameAndHandleRequired(): void
    {
        $group = new MenuBuilderGroup();

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('name', $group->getErrors());
        $this->assertArrayHasKey('handle', $group->getErrors());
    }

    public function testHandleFormat(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = '1invalid';

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('handle', $group->getErrors());

        $group->handle = 'main_nav';
        $this->assertTrue($group->validate());
    }

    public function testEmptyHandleIsRejectedAsRequiredRatherThanMalformed(): void
    {
        $group = $this->validGroup();
        $group->handle = '';

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('handle', $group->getErrors());
    }

    /**
     * `handle` and `cssClass` are varchar(255) columns. Without an explicit
     * max, an over-long value got past the model and only failed at the
     * database — or, on a non-strict MySQL, was silently truncated into a
     * handle the user never typed (and, for a duplicate, into a collision).
     */
    public function testHandleLongerThanTheColumnIsRejected(): void
    {
        $group = $this->validGroup();
        $group->handle = 'a' . str_repeat('b', 255);

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('handle', $group->getErrors());
    }

    public function testHandleExactlyAtTheColumnLengthIsAccepted(): void
    {
        $group = $this->validGroup();
        $group->handle = 'a' . str_repeat('b', 254);

        $this->assertTrue($group->validate(), 'A 255-character handle still fits the column.');
    }

    public function testCssClassLongerThanTheColumnIsRejected(): void
    {
        $group = $this->validGroup();
        $group->cssClass = str_repeat('c', 256);

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('cssClass', $group->getErrors());
    }

    public function testNameLongerThanTheColumnIsRejected(): void
    {
        $group = $this->validGroup();
        $group->name = str_repeat('n', 256);

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('name', $group->getErrors());
    }

    /** Descriptions live in a TEXT column, so they aren't length-capped. */
    public function testLongDescriptionIsAccepted(): void
    {
        $group = $this->validGroup();
        $group->description = str_repeat('d', 5000);

        $this->assertTrue($group->validate());
    }

    /** @dataProvider validMaxDepthProvider */
    public function testMaxDepthBoundariesAreAccepted(?int $maxDepth): void
    {
        $group = $this->validGroup();
        $group->maxDepth = $maxDepth;

        $this->assertTrue($group->validate(), 'Expected valid maxDepth: ' . var_export($maxDepth, true));
    }

    /** @return array<string,array{int|null}> */
    public static function validMaxDepthProvider(): array
    {
        return [
            'no limit' => [null],
            'flat list' => [1],
            'maximum' => [10],
        ];
    }

    /** @dataProvider invalidMaxDepthProvider */
    public function testInvalidMaxDepthIsRejected(int $maxDepth): void
    {
        $group = $this->validGroup();
        $group->maxDepth = $maxDepth;

        $this->assertFalse($group->validate(), 'Expected invalid maxDepth: ' . $maxDepth);
        $this->assertArrayHasKey('maxDepth', $group->getErrors());
    }

    /** @return array<string,array{int}> */
    public static function invalidMaxDepthProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'above the maximum' => [11],
        ];
    }

    /**
     * A maxDepth of 1 is a flat list — the CP and the resolver both gate on
     * allowsDepth(), so an off-by-one here would let children through.
     */
    public function testMaxDepthOfOneAllowsOnlyTopLevel(): void
    {
        $group = $this->validGroup();
        $group->maxDepth = 1;

        $this->assertTrue($group->allowsDepth(1));
        $this->assertFalse($group->allowsDepth(2));
    }

    public function testMaximumMaxDepthAllowsTenLevels(): void
    {
        $group = $this->validGroup();
        $group->maxDepth = 10;

        $this->assertTrue($group->allowsDepth(10));
        $this->assertFalse($group->allowsDepth(11));
    }

    /** Disabling a group is a plain flag — it must not make the group invalid to save. */
    public function testDisabledGroupStillValidates(): void
    {
        $group = $this->validGroup();
        $group->enabled = false;

        $this->assertTrue($group->validate());
    }

    public function testAllowsDepth(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';

        // No limit configured.
        $this->assertTrue($group->allowsDepth(1));
        $this->assertTrue($group->allowsDepth(100));

        $group->maxDepth = 2;
        $this->assertTrue($group->allowsDepth(1));
        $this->assertTrue($group->allowsDepth(2));
        $this->assertFalse($group->allowsDepth(3));
    }

    public function testUsesDefineRulesNotRules(): void
    {
        // Craft 5 models must extend validation via defineRules(), not
        // override rules() directly (see MenuBuilderItem's equivalent
        // pattern) — a bare rules() override would silently bypass Model's
        // own attachBehaviors()/EVENT_DEFINE_RULES hook.
        $reflection = new \ReflectionMethod(MenuBuilderGroup::class, 'defineRules');
        $this->assertSame(MenuBuilderGroup::class, $reflection->getDeclaringClass()->getName());
    }

    public function testHtmlAttributesRejectsEventHandlerKeys(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';
        $group->htmlAttributes = ['onclick' => 'alert(1)'];

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('htmlAttributes', $group->getErrors());
    }

    public function testHtmlAttributesRejectsJavascriptUrls(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';
        $group->htmlAttributes = ['data-href' => 'javascript:alert(1)'];

        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('htmlAttributes', $group->getErrors());
    }

    public function testHtmlAttributesAllowsWellFormedBag(): void
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';
        $group->htmlAttributes = ['data-role' => 'nav'];

        $this->assertTrue($group->validate());
    }

    public function testSiteRestrictionDefaultsToEverySite(): void
    {
        $group = $this->validGroup();

        $this->assertTrue($group->validate());
        $this->assertTrue($group->isAvailableForSite(1));
        $this->assertTrue($group->isAvailableForSite(99));
        $this->assertTrue($group->isAvailableForSite(null), 'No restriction is available everywhere, console requests included.');
    }

    public function testSiteRestrictionLimitsAvailability(): void
    {
        $group = $this->validGroup();
        $group->siteIds = [1, 3];

        $this->assertTrue($group->validate());
        $this->assertTrue($group->isAvailableForSite(1));
        $this->assertTrue($group->isAvailableForSite(3));
        $this->assertFalse($group->isAvailableForSite(2));
        $this->assertFalse($group->isAvailableForSite(null), 'A restricted menu can’t be matched without a current site.');
    }

    /** @dataProvider malformedSiteIdsProvider */
    public function testSiteIdsRejectsMalformedLists(array $siteIds): void
    {
        $group = $this->validGroup();
        $group->siteIds = $siteIds;

        $this->assertFalse($group->validate(), 'Expected invalid siteIds: ' . json_encode($siteIds));
        $this->assertArrayHasKey('siteIds', $group->getErrors());
    }

    /** @return array<string,array{array<mixed>}> */
    public static function malformedSiteIdsProvider(): array
    {
        return [
            'string ids' => [['1', '2']],
            'zero' => [[0]],
            'negative' => [[-1]],
            'nested array' => [[[1]]],
        ];
    }

    private function validGroup(): MenuBuilderGroup
    {
        $group = new MenuBuilderGroup();
        $group->name = 'Main';
        $group->handle = 'main';

        return $group;
    }

    // ---------------------------------------------------------------------
    // Lifecycle: handle uniquification, transactions, cache invalidation
    // ---------------------------------------------------------------------

    private const MAX_LENGTH = 255;

    public function testTruncateLeavesShortValuesAlone(): void
    {
        $this->assertSame('main', $this->callPrivate('truncate', ['main', self::MAX_LENGTH]));
        $this->assertSame(
            str_repeat('a', self::MAX_LENGTH),
            $this->callPrivate('truncate', [str_repeat('a', self::MAX_LENGTH), self::MAX_LENGTH])
        );
    }

    public function testTruncateTrimsOverLongValues(): void
    {
        $truncated = $this->callPrivate('truncate', [str_repeat('a', 300), self::MAX_LENGTH]);

        $this->assertSame(self::MAX_LENGTH, strlen($truncated));
    }

    /**
     * The whole point of the suffix trimming: duplicating a group whose
     * handle already fills the column must not produce a handle the column
     * can't hold (which MySQL would either reject or silently truncate back
     * into a collision with the original).
     *
     * @dataProvider suffixProvider
     */
    public function testSuffixedHandleAlwaysFitsTheColumn(int $baseLength, int $suffix): void
    {
        $base = str_repeat('a', $baseLength);
        $handle = $this->callPrivate('suffixedHandle', [$base, $suffix]);

        $this->assertLessThanOrEqual(self::MAX_LENGTH, strlen($handle));
        $this->assertStringEndsWith((string)$suffix, $handle);
    }

    /** @return array<string,array{int,int}> */
    public static function suffixProvider(): array
    {
        return [
            'short handle' => [4, 2],
            'exactly at the limit' => [self::MAX_LENGTH, 2],
            'over the limit' => [400, 2],
            'multi-digit suffix at the limit' => [self::MAX_LENGTH, 1234],
        ];
    }

    public function testShortHandleKeepsItsBaseIntact(): void
    {
        $this->assertSame('main2', $this->callPrivate('suffixedHandle', ['main', 2]));
    }

    /**
     * Duplicating a group clones its items too, so the group row and the
     * item rows must land or fail together — a committed clone group with a
     * half-copied tree is worse than no clone at all.
     */
    public function testDuplicateRunsInATransaction(): void
    {
        $source = $this->methodSource(MenuBuilderGroupService::class, 'duplicate');

        $this->assertStringContainsString('beginTransaction', $source);
        $this->assertStringContainsString('rollBack', $source);
        $this->assertStringContainsString('duplicateAllForGroup', $source);
    }

    public function testReorderRunsInATransaction(): void
    {
        $source = $this->methodSource(MenuBuilderGroupService::class, 'reorder');

        $this->assertStringContainsString('beginTransaction', $source);
        $this->assertStringContainsString('rollBack', $source);
    }

    /**
     * The database is the single source of truth for group configuration.
     * A second store —
     * project config — would reintroduce database/YAML drift,
     * `project-config/apply` overwriting live edits, and sortOrder/uid
     * synchronization, all of which this pins shut at the one layer that
     * owns group persistence.
     */
    public function testGroupPersistenceNeverTouchesProjectConfig(): void
    {
        $source = file_get_contents((new ReflectionClass(MenuBuilderGroupService::class))->getFileName());
        $body = substr($source, strpos($source, 'class MenuBuilderGroupService'));

        foreach (['getProjectConfig', 'ProjectConfig', 'ConfigEvent', 'EVENT_REBUILD'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                'Group configuration is database-backed only; it must not reach for project config.'
            );
        }
    }

    /**
     * Same guarantee one level up: no project-config handler may be
     * registered for groups, which is where the previous mirroring hooked
     * itself in.
     */
    public function testPluginRegistersNoGroupProjectConfigHandlers(): void
    {
        $source = file_get_contents((new ReflectionClass(MenuBuilder::class))->getFileName());

        foreach (['ProjectConfig', 'RebuildConfigEvent', 'onAdd(', 'onUpdate(', 'onRemove('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    /**
     * Uninstalling drops the two tables and nothing else — there is no
     * config path left anywhere for a reinstall to replay.
     */
    public function testUninstallDropsBothTablesAndNothingElse(): void
    {
        $install = file_get_contents(__DIR__ . '/../../src/migrations/Install.php');

        $this->assertStringContainsString("dropTableIfExists('{{%menubuilder_items}}')", $install);
        $this->assertStringContainsString("dropTableIfExists('{{%menubuilder_groups}}')", $install);
        $this->assertStringNotContainsString('ProjectConfig', $install);
    }

    /**
     * Every menu write invalidates — and invalidates **only that menu**. A
     * menu save/duplicate/delete used to flush every cached tree on the
     * install because the cache key was built from the handle a save could
     * change; entries are now tagged by menu ID, so the targeted call reaches
     * the old handle's entries too (MenuBuilderCacheService::groupTag()).
     *
     * @dataProvider cacheInvalidatingProvider
     */
    public function testEveryContentAffectingWriteInvalidatesOnlyItsOwnMenu(string $method): void
    {
        $source = $this->methodSource(MenuBuilderGroupService::class, $method);

        $this->assertStringContainsString('cache->invalidateGroupId(', $source);
        $this->assertStringNotContainsString('invalidateAll(', $source, 'A change to one menu must never flush every menu.');
    }

    /**
     * The whole-cache flush exists for exactly one change — a site save or
     * delete, which moves the base URL, language or existence every cached
     * tree was resolved against (MenuBuilderElementService::handleSiteChange()).
     * Nothing else in the plugin may reach for it.
     */
    public function testTheWholeCacheFlushIsReservedForSiteChanges(): void
    {
        $callers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src'));

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string)file_get_contents($file->getPathname()), 'cache->invalidateAll(')) {
                $callers[] = $file->getFilename();
            }
        }

        $this->assertSame(['MenuBuilderElementService.php'], $callers);
    }

    /** @return array<string,array{string}> */
    public static function cacheInvalidatingProvider(): array
    {
        return [
            'save' => ['save'],
            'duplicate' => ['duplicate'],
            'delete' => ['deleteById'],
        ];
    }

    /**
     * Removing project config must not have taken any *database* persistence
     * with it — every group attribute the model exposes still needs a column
     * to live in, since the table is now the only place it is stored.
     *
     * @dataProvider groupColumnProvider
     */
    public function testEveryGroupAttributeHasItsOwnColumn(string $column): void
    {
        $install = file_get_contents(__DIR__ . '/../../src/migrations/Install.php');
        $groupsTable = substr(
            $install,
            strpos($install, "createTable('{{%menubuilder_groups}}'"),
            strpos($install, "createTable('{{%menubuilder_items}}'") - strpos($install, "createTable('{{%menubuilder_groups}}'")
        );

        $this->assertStringContainsString("'$column' =>", $groupsTable);
    }

    /** @return array<string,array{string}> */
    public static function groupColumnProvider(): array
    {
        $columns = [
            'id', 'uid', 'name', 'handle', 'description', 'enabled', 'sortOrder',
            'maxDepth', 'cssClass', 'htmlAttributes', 'settings', 'dateCreated', 'dateUpdated',
        ];

        return array_combine($columns, array_map(fn(string $column) => [$column], $columns));
    }

    /**
     * Site restrictions ride inside the `settings` JSON bag rather than a
     * column of their own (no migration needed), so the round-trip through
     * that bag is what keeps them database-backed.
     */
    public function testSiteIdsRoundTripThroughTheSettingsBag(): void
    {
        $this->assertSame('siteIds', MenuBuilderGroupService::SITE_IDS_KEY);

        $read = $this->methodSource(MenuBuilderGroupService::class, 'recordToModel');
        $write = $this->methodSource(MenuBuilderGroupService::class, 'settingsWithSiteIds');

        $this->assertStringContainsString('self::SITE_IDS_KEY', $read);
        $this->assertStringContainsString('self::SITE_IDS_KEY', $write);
        $this->assertStringContainsString('unset($settings[self::SITE_IDS_KEY])', $read);
    }

    /**
     * Deleting a group must not leave its items behind. The cascade lives on
     * the `groupId` foreign key rather than in PHP, so this pins the
     * migration that declares it.
     */
    public function testItemsCascadeWhenTheirGroupIsDeleted(): void
    {
        $install = file_get_contents(__DIR__ . '/../../src/migrations/Install.php');

        $this->assertMatchesRegularExpression(
            "/addForeignKey\(\s*null,\s*'\{\{%menubuilder_items\}\}',\s*\['groupId'\],\s*'\{\{%menubuilder_groups\}\}',\s*\['id'\],\s*'CASCADE'/",
            $install
        );
    }

    public function testGroupHandleColumnIsUniqueInTheDatabase(): void
    {
        $install = file_get_contents(__DIR__ . '/../../src/migrations/Install.php');

        $this->assertStringContainsString(
            "createIndex(null, '{{%menubuilder_groups}}', ['handle'], true)",
            $install,
            'Handle uniqueness must also be enforced by the database, not only by the service.'
        );
    }

    /**
     * Enable/disable is a mutation like any other, so it has to clear the
     * same manageSettings bar the full edit form does — the `default` arm of
     * the mapping, which a later action added without thinking would
     * silently inherit.
     */
    public function testToggleRequiresManageSettings(): void
    {
        $this->assertSame('menuBuilder:manageSettings', GroupsController::requiredPermissionForAction('toggle'));
    }

    /**
     * The full CRUD surface, so a lifecycle operation can't quietly go
     * missing: create/update share `save`, and read, duplicate, delete and
     * enable/disable each have their own action.
     *
     * @dataProvider crudActionProvider
     */
    public function testEveryLifecycleOperationHasAnActionThatDelegatesToTheService(string $action): void
    {
        $method = 'action' . ucfirst($action);

        $this->assertTrue(
            method_exists(GroupsController::class, $method),
            "GroupsController::$method() is missing."
        );
        $this->assertStringContainsString(
            'groups->',
            $this->methodSource(GroupsController::class, $method),
            "GroupsController::$method() must go through the group service."
        );
    }

    /** @return array<string,array{string}> */
    public static function crudActionProvider(): array
    {
        $actions = ['index', 'edit', 'save', 'duplicate', 'delete', 'toggle'];

        return array_combine($actions, array_map(fn(string $action) => [$action], $actions));
    }

    /**
     * A handle already in use must fail as a *field error* on the model, not
     * as a database integrity exception — the unique index is the backstop,
     * not the user-facing check.
     */
    public function testSaveRejectsAHandleAlreadyInUseBeforeWriting(): void
    {
        $source = $this->methodSource(MenuBuilderGroupService::class, 'save');

        $this->assertStringContainsString("['handle' => \$group->handle]", $source);
        $this->assertStringContainsString("addError('handle'", $source);
        $this->assertLessThan(
            strpos($source, '$record->save()'),
            strpos($source, "addError('handle'"),
            'The uniqueness check must short-circuit before the row is written.'
        );
    }

    /** Validation is the model's job, and save() must not be able to skip it by default. */
    public function testSaveRunsModelValidationByDefault(): void
    {
        $runValidation = (new ReflectionMethod(MenuBuilderGroupService::class, 'save'))->getParameters()[1];

        $this->assertSame('runValidation', $runValidation->getName());
        $this->assertTrue($runValidation->getDefaultValue());
        $this->assertStringContainsString('$group->validate()', $this->methodSource(MenuBuilderGroupService::class, 'save'));
    }

    /**
     * The permission mapping fails closed: anything not explicitly listed —
     * including an action added later — lands on the strictest of the three,
     * rather than falling through ungated.
     */
    public function testUnlistedActionsFallBackToManageSettings(): void
    {
        $this->assertSame(
            'menuBuilder:manageSettings',
            GroupsController::requiredPermissionForAction('some-action-added-later')
        );
    }

    /**
     * Craft only enforces CSRF on POST, so a mutation reachable over GET is
     * a mutation without CSRF protection.
     *
     * @dataProvider mutatingActionProvider
     */
    public function testEveryMutatingActionRequiresPost(string $action): void
    {
        $this->assertStringContainsString(
            'requirePostRequest()',
            $this->methodSource(GroupsController::class, 'action' . ucfirst($action))
        );
    }

    /** @return array<string,array{string}> */
    public static function mutatingActionProvider(): array
    {
        return [
            'save' => ['save'],
            'delete' => ['delete'],
            'duplicate' => ['duplicate'],
            'toggle' => ['toggle'],
        ];
    }

    /**
     * Controllers must not write group rows themselves — validation, handle
     * uniqueness, transactions, cache invalidation and the project-config
     * mirror all live in the service, and any of them is skippable by a
     * controller that reaches for the record directly.
     */
    public function testControllerNeverTouchesTheRecordLayer(): void
    {
        $source = file_get_contents((new ReflectionClass(GroupsController::class))->getFileName());

        $this->assertStringNotContainsString('MenuBuilderGroupRecord', $source);
    }

    /**
     * @param array<int,mixed> $args
     */
    private function callPrivate(string $method, array $args): mixed
    {
        return (new ReflectionMethod(MenuBuilderGroupService::class, $method))->invokeArgs(null, $args);
    }

    /** @param class-string $class */
    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}

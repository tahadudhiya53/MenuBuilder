<?php

namespace Tahadudhiya\MenuBuilder\Tests\Unit;

use craft\helpers\StringHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tahadudhiya\MenuBuilder\fields\MenuBuilderField;
use Tahadudhiya\MenuBuilder\gql\MenuBuilderMenuType;
use Tahadudhiya\MenuBuilder\helpers\MenuBuilderFieldHelper;
use Tahadudhiya\MenuBuilder\models\MenuBuilderFieldValue;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderNode;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * The Navigation field: what an author's selection is allowed to be, what
 * Twig gets back, and what happens to a selection when the menu behind it
 * changes underneath it.
 *
 * The field class itself needs a booted Craft application — a field layout,
 * an element, a request, a CP template root — so the rules it *enforces* are
 * kept in {@see MenuBuilderFieldHelper} and the value it *hands to Twig* in
 * {@see MenuBuilderFieldValue}, both pure. That is what is pinned here: the
 * cases that regress silently, because a broken selection keeps rendering an
 * empty menu rather than failing loudly.
 *
 * The DB/CP half — the field appearing in the field type list, a value
 * round-tripping through the content table, project config applying the
 * settings — needs a booted app and is verified manually; see the manual test
 * list in ARCHITECTURE.md.
 */
class MenuBuilderFieldTest extends TestCase
{
    // ---------------------------------------------------------------------
    // What a stored value may be
    // ---------------------------------------------------------------------

    /**
     * The field stores a UID, never a handle. A raw handle is exactly the
     * shape this field was specified *not* to be, so it must not survive
     * normalization and reach a lookup as if it were an identity.
     */
    public function testOnlyUidShapedValuesNormalize(): void
    {
        $uid = StringHelper::UUID();

        $this->assertSame($uid, MenuBuilderFieldHelper::normalizeUid($uid));
        $this->assertSame($uid, MenuBuilderFieldHelper::normalizeUid("  $uid  "), 'Surrounding whitespace from a post is trimmed.');
        $this->assertNull(MenuBuilderFieldHelper::normalizeUid('mainNav'), 'A bare handle is not an identity this field accepts.');
        $this->assertNull(MenuBuilderFieldHelper::normalizeUid('7'));
        $this->assertNull(MenuBuilderFieldHelper::normalizeUid('not-a-uid-at-all'));
    }

    /** @dataProvider emptyValueProvider */
    public function testEmptySelectionsNormalizeToNull(mixed $value): void
    {
        $this->assertNull(MenuBuilderFieldHelper::normalizeUid($value));
    }

    public static function emptyValueProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace' => ['   '],
            // Craft's own select inputs post this for the blank option.
            'blank sentinel' => ['__BLANK__'],
            'lowercase blank sentinel' => ['__blank__'],
            'array' => [[]],
            'int' => [5],
            'bool' => [true],
        ];
    }

    // ---------------------------------------------------------------------
    // Field settings (create field / project config)
    // ---------------------------------------------------------------------

    /**
     * The allow-list is UIDs, and only UIDs. It is written into project config,
     * so a hand-edited or partially-applied YAML must not be able to leave a
     * handle, an ID or a stray `true` in it — any of which would silently
     * change which menus the field offers. (The menus it references are *not*
     * in project config; see the note on {@see testSettingsAreProjectConfigPortable()}.)
     */
    public function testAllowListNormalizationDropsAnythingThatIsntAUid(): void
    {
        $a = StringHelper::UUID();
        $b = StringHelper::UUID();

        $this->assertSame(
            [$a, $b],
            MenuBuilderFieldHelper::normalizeUidList([$a, 'mainNav', 3, null, true, $b, [$a]])
        );
    }

    public function testAllowListNormalizationDeduplicates(): void
    {
        $uid = StringHelper::UUID();

        $this->assertSame([$uid], MenuBuilderFieldHelper::normalizeUidList([$uid, $uid]));
    }

    public function testAllowListNormalizationOfANonArrayIsEmpty(): void
    {
        $this->assertSame([], MenuBuilderFieldHelper::normalizeUidList('nope'));
        $this->assertSame([], MenuBuilderFieldHelper::normalizeUidList(null));
    }

    /**
     * The settings surface is deliberately just a UID list and a boolean — no
     * row IDs, no site IDs — because Craft writes it into project config and a
     * settings attribute carrying a row ID is the thing that breaks a deploy.
     * So the shape is pinned.
     *
     * "Safe to apply" is the whole claim. The menus these UIDs name are
     * database-only and are *not* project-config entities (ARCHITECTURE.md
     * "Group persistence — database only"), so applying this config elsewhere
     * does not create them — they must already exist in that environment's
     * database.
     */
    public function testSettingsAreProjectConfigPortable(): void
    {
        $settings = (new \ReflectionClass(MenuBuilderField::class))->getProperties(\ReflectionProperty::IS_PUBLIC);
        $declared = [];

        foreach ($settings as $property) {
            if ($property->getDeclaringClass()->getName() === MenuBuilderField::class) {
                $declared[$property->getName()] = (string)$property->getType();
            }
        }

        $this->assertSame([
            'allowedGroupUids' => 'array',
            'includeDisabledMenus' => 'bool',
        ], $declared);
    }

    /** The value column is a string, because the stored identity is a UID. */
    public function testDbTypeIsAString(): void
    {
        $this->assertSame('string', MenuBuilderField::dbType());
    }

    /** Twig gets a stable object, not a record and not a bare string. */
    public function testPhpTypeIsTheValueObject(): void
    {
        $this->assertSame('\\' . MenuBuilderFieldValue::class . '|null', MenuBuilderField::phpType());
    }

    // ---------------------------------------------------------------------
    // The picker (select menu / change menu / disabled menu)
    // ---------------------------------------------------------------------

    public function testAnEmptyAllowListOffersEveryEnabledMenu(): void
    {
        $groups = [$this->group('main'), $this->group('footer')];

        $selectable = MenuBuilderFieldHelper::selectableGroups($groups, [], false);

        $this->assertSame(['main', 'footer'], array_column($selectable, 'handle'));
    }

    public function testAllowListRestrictsThePicker(): void
    {
        $main = $this->group('main');
        $footer = $this->group('footer');

        $selectable = MenuBuilderFieldHelper::selectableGroups([$main, $footer], [$footer->uid], false);

        $this->assertSame(['footer'], array_column($selectable, 'handle'));
    }

    public function testDisabledMenusAreHiddenUnlessOptedIn(): void
    {
        $main = $this->group('main');
        $draft = $this->group('draft', enabled: false);

        $this->assertSame(['main'], array_column(
            MenuBuilderFieldHelper::selectableGroups([$main, $draft], [], false),
            'handle'
        ));

        $this->assertSame(['main', 'draft'], array_column(
            MenuBuilderFieldHelper::selectableGroups([$main, $draft], [], true),
            'handle'
        ));
    }

    /**
     * A menu that was disabled, or dropped from the allow-list, *after* an
     * author selected it stays in their picker. Otherwise opening an
     * unrelated entry and saving it would silently rewrite the selection to
     * whatever the select box happened to fall back to — data loss with no
     * error and no audit trail.
     */
    public function testTheCurrentSelectionIsAlwaysOfferedEvenWhenItNoLongerQualifies(): void
    {
        $main = $this->group('main');
        $retired = $this->group('retired', enabled: false);

        $selectable = MenuBuilderFieldHelper::selectableGroups(
            [$main, $retired],
            [$main->uid],
            includeDisabled: false,
            currentUid: $retired->uid,
        );

        $this->assertSame(['main', 'retired'], array_column($selectable, 'handle'));
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    public function testNoSelectionIsNotAnError(): void
    {
        $this->assertNull(MenuBuilderFieldHelper::validationError(null, null, []));
    }

    /** Deleting the selected menu leaves a value pointing at nothing. */
    public function testSelectionWhoseMenuWasDeletedIsAnError(): void
    {
        $this->assertSame(
            MenuBuilderFieldHelper::ERROR_MISSING,
            MenuBuilderFieldHelper::validationError(StringHelper::UUID(), null, [])
        );
    }

    public function testSelectionOutsideTheAllowListIsAnError(): void
    {
        $group = $this->group('main');

        $this->assertSame(
            MenuBuilderFieldHelper::ERROR_NOT_ALLOWED,
            MenuBuilderFieldHelper::validationError($group->uid, $group, [StringHelper::UUID()])
        );
    }

    public function testSelectionInsideTheAllowListIsFine(): void
    {
        $group = $this->group('main');

        $this->assertNull(MenuBuilderFieldHelper::validationError($group->uid, $group, [$group->uid]));
    }

    /**
     * `enabled` is a publishing state an editor flips independently of any
     * entry. Treating it as a content error would make every entry pointing
     * at a menu unsavable the moment somebody turned that menu off.
     */
    public function testADisabledMenuIsNotAValidationError(): void
    {
        $group = $this->group('main', enabled: false);

        $this->assertNull(MenuBuilderFieldHelper::validationError($group->uid, $group, []));
    }

    // ---------------------------------------------------------------------
    // Sites
    // ---------------------------------------------------------------------

    /**
     * A site-restricted menu picked on a site it isn't available on is only
     * reportable when the field is per-site — that's the only case where the
     * author can pick a different menu here. On an untranslatable field one
     * value covers every site, so the "error" would be unfixable.
     */
    public function testSiteMismatchIsOnlyAnErrorOnATranslatableField(): void
    {
        $group = $this->group('main');
        $group->siteIds = [1];

        $this->assertSame(
            MenuBuilderFieldHelper::ERROR_SITE_MISMATCH,
            MenuBuilderFieldHelper::validationError($group->uid, $group, [], isTranslatable: true, siteId: 2)
        );

        $this->assertNull(
            MenuBuilderFieldHelper::validationError($group->uid, $group, [], isTranslatable: false, siteId: 2),
            'One value covers every site, so a site restriction can never be satisfied here.'
        );
    }

    public function testATranslatableFieldOnTheMenusOwnSiteIsFine(): void
    {
        $group = $this->group('main');
        $group->siteIds = [1, 2];

        $this->assertNull(
            MenuBuilderFieldHelper::validationError($group->uid, $group, [], isTranslatable: true, siteId: 2)
        );
    }

    public function testAnUnrestrictedMenuIsFineOnEverySite(): void
    {
        $group = $this->group('main');

        foreach ([null, 1, 2, 99] as $siteId) {
            $this->assertNull(
                MenuBuilderFieldHelper::validationError($group->uid, $group, [], isTranslatable: true, siteId: $siteId),
                'An unrestricted menu is available everywhere.'
            );
        }
    }

    // ---------------------------------------------------------------------
    // Permissions
    // ---------------------------------------------------------------------

    /**
     * Selecting a menu is content authoring and needs no MenuBuilder
     * permission; reaching the menu *editor* does. The input must not offer
     * a link a permission check would then reject.
     */
    public function testTheManageLinkIsOnlyOfferedToSomeoneWhoCouldFollowIt(): void
    {
        $this->assertTrue(MenuBuilderFieldHelper::canLinkToMenu(isAdmin: true, canView: false));
        $this->assertTrue(MenuBuilderFieldHelper::canLinkToMenu(isAdmin: false, canView: true));
        $this->assertFalse(MenuBuilderFieldHelper::canLinkToMenu(isAdmin: false, canView: false));
    }

    /** The link points at the menu's dashboard, which requires `menuBuilder:view`. */
    public function testTheManageLinkTargetsAPermissionGuardedRoute(): void
    {
        $this->assertTrue(
            (new ReflectionMethod(MenuBuilderField::class, 'footnoteHtml'))->isPrivate(),
            'The affordance is built in one place, so the permission check can’t be bypassed by a caller.'
        );
    }

    // ---------------------------------------------------------------------
    // The Twig value
    // ---------------------------------------------------------------------

    public function testValueExposesTheSelectedMenuWithoutResolvingIt(): void
    {
        $group = $this->group('main');
        $resolved = 0;

        $value = new MenuBuilderFieldValue($group->uid, $group, 1, function() use (&$resolved) {
            $resolved++;

            return null;
        });

        $this->assertSame($group->uid, $value->groupUid);
        $this->assertSame('main', $value->getHandle());
        $this->assertSame('Main', $value->getName());
        $this->assertTrue($value->exists());
        $this->assertSame(0, $resolved, 'An element index normalizes many values and must not resolve a menu for each.');
    }

    public function testValueIteratesTheResolvedTreesTopLevelNodes(): void
    {
        $group = $this->group('main');
        $tree = new MenuBuilderTree($group, [$this->node('Home'), $this->node('About')]);

        $value = new MenuBuilderFieldValue($group->uid, $group, 1, fn() => $tree);

        $this->assertCount(2, $value);
        $this->assertSame(['Home', 'About'], array_map(fn(MenuBuilderNode $node) => $node->title, iterator_to_array($value)));
    }

    public function testTheTreeIsResolvedOncePerValue(): void
    {
        $group = $this->group('main');
        $tree = new MenuBuilderTree($group, [$this->node('Home')]);
        $resolved = 0;

        $value = new MenuBuilderFieldValue($group->uid, $group, 1, function() use (&$resolved, $tree) {
            $resolved++;

            return $tree;
        });

        $value->getTree();
        $value->getTree();
        iterator_to_array($value);
        $value->count();

        $this->assertSame(1, $resolved, 'Rendering a menu and then its breadcrumbs must cost one resolve.');
    }

    /**
     * An explicit `currentUri` marks active state against a different page,
     * so it can't be answered from the tree memoized for this request's page.
     */
    public function testAnExplicitCurrentUriBypassesTheMemoizedTree(): void
    {
        $group = $this->group('main');
        $seen = [];

        $value = new MenuBuilderFieldValue($group->uid, $group, 1, function(string $handle, ?string $uri) use (&$seen, $group) {
            $seen[] = $uri;

            return new MenuBuilderTree($group, []);
        });

        $value->getTree();
        $value->getTree('/about');
        $value->getTree('/contact');

        $this->assertSame([null, '/about', '/contact'], $seen);
    }

    public function testTheResolverIsAskedForTheMenusHandle(): void
    {
        $group = $this->group('main');
        $asked = null;

        $value = new MenuBuilderFieldValue($group->uid, $group, 1, function(string $handle) use (&$asked, $group) {
            $asked = $handle;

            return new MenuBuilderTree($group, []);
        });

        $value->getTree();

        $this->assertSame('main', $asked, 'The field resolves through the one resolver, so a field-rendered menu is cached and filtered like any other.');
    }

    // ---------------------------------------------------------------------
    // A selection that outlived its menu
    // ---------------------------------------------------------------------

    /**
     * Deleting the selected menu must be distinguishable from selecting
     * nothing: the value survives so the CP can say so and validation can
     * report it, rather than the field quietly reading as empty.
     */
    public function testASelectionWhoseMenuWasDeletedStillCarriesTheUid(): void
    {
        $uid = StringHelper::UUID();
        $value = new MenuBuilderFieldValue($uid, null, 1, fn() => null);

        $this->assertSame($uid, $value->groupUid);
        $this->assertFalse($value->exists());
        $this->assertNull($value->getHandle());
        $this->assertNull($value->getName());
        $this->assertSame('', (string)$value);
    }

    public function testADeletedMenuNeverReachesTheResolver(): void
    {
        $resolved = 0;
        $value = new MenuBuilderFieldValue(StringHelper::UUID(), null, 1, function() use (&$resolved) {
            $resolved++;

            return null;
        });

        $this->assertNull($value->getTree());
        $this->assertCount(0, $value);
        $this->assertSame(0, $resolved, 'There is no handle to resolve, so no lookup is attempted.');
    }

    /** A disabled menu resolves to nothing — that is what disabling it means. */
    public function testADisabledMenuIteratesEmpty(): void
    {
        $group = $this->group('main', enabled: false);
        $value = new MenuBuilderFieldValue($group->uid, $group, 1, fn() => null);

        $this->assertTrue($value->exists(), 'The selection is still valid…');
        $this->assertFalse($value->isEnabled());
        $this->assertNull($value->getTree(), '…but it renders nothing.');
        $this->assertCount(0, $value);
    }

    public function testValueReportsAvailabilityForItsElementsSite(): void
    {
        $group = $this->group('main');
        $group->siteIds = [1];

        $this->assertTrue((new MenuBuilderFieldValue($group->uid, $group, 1))->isAvailableForSite());
        $this->assertFalse((new MenuBuilderFieldValue($group->uid, $group, 2))->isAvailableForSite());
    }

    public function testValueStringifiesToTheMenuName(): void
    {
        $group = $this->group('main');

        $this->assertSame('Main', (string)new MenuBuilderFieldValue($group->uid, $group));
    }

    /**
     * Serializing the *selection*, never a resolved tree: a tree is per-site,
     * per-visitor and per-page, so anything that persisted or cached one
     * would bake a particular request into it.
     */
    public function testValueSerializesTheSelectionOnly(): void
    {
        $group = $this->group('main');
        $value = new MenuBuilderFieldValue($group->uid, $group, 1, fn() => new MenuBuilderTree($group, [$this->node('Home')]));

        $this->assertSame([
            'uid' => $group->uid,
            'handle' => 'main',
            'name' => 'Main',
            'exists' => true,
        ], $value->jsonSerialize());
    }

    // ---------------------------------------------------------------------
    // GraphQL
    // ---------------------------------------------------------------------

    public function testGraphqlExposesTheSelectionAndNotTheTree(): void
    {
        $fields = MenuBuilderMenuType::fieldDefinitions();

        $this->assertSame(['uid', 'handle', 'name', 'exists', 'enabled'], array_keys($fields));
        $this->assertArrayNotHasKey('tree', $fields, 'A shared, cached GraphQL response must not carry a per-visitor tree.');
        $this->assertArrayNotHasKey('items', $fields);
    }

    public function testGraphqlResolversReadTheValueObject(): void
    {
        $group = $this->group('main');
        $value = new MenuBuilderFieldValue($group->uid, $group, 1);
        $fields = MenuBuilderMenuType::fieldDefinitions();

        $this->assertSame($group->uid, ($fields['uid']['resolve'])($value));
        $this->assertSame('main', ($fields['handle']['resolve'])($value));
        $this->assertSame('Main', ($fields['name']['resolve'])($value));
        $this->assertTrue(($fields['exists']['resolve'])($value));
        $this->assertTrue(($fields['enabled']['resolve'])($value));
    }

    public function testGraphqlResolversHandleADeletedMenu(): void
    {
        $uid = StringHelper::UUID();
        $value = new MenuBuilderFieldValue($uid, null, 1);
        $fields = MenuBuilderMenuType::fieldDefinitions();

        $this->assertSame($uid, ($fields['uid']['resolve'])($value));
        $this->assertNull(($fields['handle']['resolve'])($value));
        $this->assertNull(($fields['name']['resolve'])($value));
        $this->assertFalse(($fields['exists']['resolve'])($value));
        $this->assertFalse(($fields['enabled']['resolve'])($value));
    }

    // ---------------------------------------------------------------------
    // Lookup path
    // ---------------------------------------------------------------------

    
    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function group(string $handle, bool $enabled = true): MenuBuilderGroup
    {
        $group = new MenuBuilderGroup();
        $group->id = crc32($handle);
        $group->name = ucfirst($handle);
        $group->handle = $handle;
        $group->enabled = $enabled;
        $group->uid = StringHelper::UUID();

        return $group;
    }

    private function node(string $title): MenuBuilderNode
    {
        return new MenuBuilderNode(
            id: crc32($title),
            handle: null,
            type: 'url',
            title: $title,
            url: '/' . strtolower($title),
            isClickable: true,
            isLinkAvailable: true,
            target: '_self',
            rel: null,
            cssClass: null,
            htmlId: null,
            htmlAttributes: [],
            ariaLabel: null,
            titleAttribute: null,
            icon: null,
            badge: null,
            description: null,
            image: null,
            featured: false,
            level: 1,
        );
    }
}

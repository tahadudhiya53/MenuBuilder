<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\fields\MenuBuilderField;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * Shared fixture for the integration suite: a real second site, real
 * MenuBuilder menus, a real Navigation field in a real entry type's field
 * layout, and real entries with real stored values.
 *
 * Everything is built through Craft's own services rather than inserted as
 * rows, because the point of this suite is to exercise the paths a Craft
 * install actually uses. Built once per run (the fixture is read-only for
 * every test that isn't explicitly mutating it) and torn down by the next
 * run's bootstrap, which drops the schema.
 */
abstract class CraftIntegrationTestCase extends TestCase
{
    protected const SECTION_HANDLE = 'pages';

    /** The shared (untranslatable) instance: one selection covers every site. */
    protected const FIELD_HANDLE = 'navigation';

    /**
     * A second, **translatable** instance in the same layout. Site-mismatch
     * validation is only reachable on a translatable field (see
     * MenuBuilderFieldHelper::validationError()), so without one in a real
     * layout that rule is unit-tested and never actually exercised by Craft.
     */
    protected const PER_SITE_FIELD_HANDLE = 'navigationPerSite';

    protected static bool $fixtureLoaded = false;

    /** The second site, for the multi-site cases. */
    protected static int $secondSiteId;

    protected static MenuBuilderGroup $mainMenu;
    protected static MenuBuilderGroup $footerMenu;

    /** Enabled, but restricted to the primary site only. */
    protected static MenuBuilderGroup $primaryOnlyMenu;

    /** Disabled, to prove a disabled selection stays stored and queryable. */
    protected static MenuBuilderGroup $disabledMenu;

    /** The UID of a menu that has since been deleted. */
    protected static string $deletedMenuUid;

    protected static MenuBuilderField $field;
    protected static EntryType $entryType;

    /**
     * The UID of the field's **layout element**, not of the field.
     *
     * Craft 5 keys `elements_sites.content` by the field layout element's UID
     * (see `Field::getValueSql()`), which is what makes the same field usable
     * more than once in one layout. Anything reading the raw column, or
     * checking the generated SQL, has to address it by this.
     */
    protected static string $fieldInstanceUid;

    /** The field as it exists *in the layout* — the only form that can produce value SQL. */
    protected static MenuBuilderField $fieldInstance;

    protected static MenuBuilderField $perSiteField;
    protected static MenuBuilderField $perSiteFieldInstance;

    /** @var array<string,int> Entry IDs by slug. */
    protected static array $entryIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$fixtureLoaded) {
            return;
        }

        self::$secondSiteId = self::createSecondSite();

        self::$mainMenu = self::createMenu('main', 'Main Navigation');
        self::$footerMenu = self::createMenu('footer', 'Footer Navigation');
        self::$primaryOnlyMenu = self::createMenu('primaryOnly', 'Primary Only Navigation', siteIds: [
            Craft::$app->getSites()->getPrimarySite()->id,
        ]);
        self::$disabledMenu = self::createMenu('retired', 'Retired Navigation', enabled: false);

        $doomed = self::createMenu('doomed', 'Doomed Navigation');
        self::$deletedMenuUid = (string)$doomed->uid;

        self::addItem(self::$mainMenu, 'Home', '/');
        self::addItem(self::$mainMenu, 'About', '/about');
        self::addItem(self::$footerMenu, 'Privacy', '/privacy');

        self::$field = self::createNavigationField(self::FIELD_HANDLE, 'Navigation');
        self::$perSiteField = self::createNavigationField(
            self::PER_SITE_FIELD_HANDLE,
            'Navigation (per site)',
            MenuBuilderField::TRANSLATION_METHOD_SITE,
        );
        self::$entryType = self::createSectionWithFields([self::$field, self::$perSiteField]);

        $layout = self::$entryType->getFieldLayout();

        /** @var MenuBuilderField $instance */
        $instance = $layout->getFieldByHandle(self::FIELD_HANDLE);
        self::$fieldInstance = $instance;
        self::$fieldInstanceUid = (string)$instance->layoutElement->uid;

        /** @var MenuBuilderField $perSiteInstance */
        $perSiteInstance = $layout->getFieldByHandle(self::PER_SITE_FIELD_HANDLE);
        self::$perSiteFieldInstance = $perSiteInstance;

        // Entries are created *before* the doomed menu is deleted, so one of
        // them ends up holding a UID whose menu no longer exists — the
        // "deleted selected menu" case, produced the way it happens in
        // practice rather than by writing a bogus value.
        self::$entryIds['picks-main'] = self::createEntry('picks-main', (string)self::$mainMenu->uid);
        self::$entryIds['picks-main-too'] = self::createEntry('picks-main-too', (string)self::$mainMenu->uid);
        self::$entryIds['picks-footer'] = self::createEntry('picks-footer', (string)self::$footerMenu->uid);
        self::$entryIds['picks-nothing'] = self::createEntry('picks-nothing', null);
        self::$entryIds['picks-disabled'] = self::createEntry('picks-disabled', (string)self::$disabledMenu->uid);
        self::$entryIds['picks-primary-only'] = self::createEntry(
            'picks-primary-only',
            (string)self::$primaryOnlyMenu->uid,
            perSiteMenuUid: (string)self::$mainMenu->uid,
        );
        self::$entryIds['picks-doomed'] = self::createEntry('picks-doomed', self::$deletedMenuUid);

        MenuBuilder::getInstance()->groups->deleteById((int)$doomed->id);

        self::$fixtureLoaded = true;
    }

    // ---------------------------------------------------------------------
    // Fixture builders
    // ---------------------------------------------------------------------

    private static function createSecondSite(): int
    {
        $sites = Craft::$app->getSites();

        $site = new Site([
            'groupId' => $sites->getPrimarySite()->groupId,
            'name' => 'Secondary',
            'handle' => 'secondary',
            'language' => 'de-DE',
            'hasUrls' => true,
            'baseUrl' => 'https://secondary.test/',
            'primary' => false,
        ]);

        if (!$sites->saveSite($site)) {
            throw new \RuntimeException('Could not create the second site: ' . json_encode($site->getErrors()));
        }

        // Craft memoizes "is this a multi-site install?" and every element
        // query consults it to decide whether to constrain `elements_sites.siteId`
        // at all. It was answered "no" when the app booted against a
        // freshly installed single-site database, so without this refresh
        // every query below silently returns one row *per site* — which would
        // turn a "matches nothing" bug into a passing test. Both caches have to
        // be refreshed — element queries consult the *with-trashed* one, which
        // is memoized separately.
        Craft::$app->getIsMultiSite(refresh: true);
        Craft::$app->getIsMultiSite(refresh: true, withTrashed: true);

        return (int)$site->id;
    }

    /** @param int[] $siteIds */
    protected static function createMenu(string $handle, string $name, bool $enabled = true, array $siteIds = []): MenuBuilderGroup
    {
        $group = new MenuBuilderGroup();
        $group->name = $name;
        $group->handle = $handle;
        $group->enabled = $enabled;
        $group->siteIds = $siteIds;

        if (!MenuBuilder::getInstance()->groups->save($group)) {
            throw new \RuntimeException("Could not create menu \"$handle\": " . json_encode($group->getErrors()));
        }

        return $group;
    }

    protected static function addItem(MenuBuilderGroup $group, string $title, string $url): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = (int)$group->id;
        $item->title = $title;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = $url;
        $item->enabled = true;

        if (!MenuBuilder::getInstance()->items->save($item)) {
            throw new \RuntimeException("Could not create item \"$title\": " . json_encode($item->getErrors()));
        }

        return $item;
    }

    private static function createNavigationField(
        string $handle,
        string $name,
        string $translationMethod = MenuBuilderField::TRANSLATION_METHOD_NONE,
    ): MenuBuilderField {
        $field = new MenuBuilderField([
            'name' => $name,
            'handle' => $handle,
            'translationMethod' => $translationMethod,
        ]);

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new \RuntimeException("Could not save the \"$handle\" field: " . json_encode($field->getErrors()));
        }

        /** @var MenuBuilderField $saved */
        $saved = Craft::$app->getFields()->getFieldByHandle($handle);

        return $saved;
    }

    /** @param FieldInterface[] $fields */
    private static function createSectionWithFields(array $fields): EntryType
    {
        $entryType = new EntryType([
            'name' => 'Page',
            'handle' => 'page',
        ]);

        $layout = new FieldLayout(['type' => Entry::class]);
        // Config arrays rather than constructed tabs: FieldLayout::setTabs()
        // wires each tab back to its layout, which a tab built in isolation
        // has no way to know about.
        $layout->setTabs([
            [
                'name' => 'Content',
                'elements' => array_map(fn(FieldInterface $field) => new CustomField($field), $fields),
            ],
        ]);
        $entryType->setFieldLayout($layout);

        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            throw new \RuntimeException('Could not save the entry type: ' . json_encode($entryType->getErrors()));
        }

        $siteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = new Section_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => true,
                'uriFormat' => '{slug}',
                'template' => 'page',
            ]);
        }

        $section = new Section([
            'name' => 'Pages',
            'handle' => self::SECTION_HANDLE,
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => $siteSettings,
        ]);
        $section->setEntryTypes([$entryType]);

        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new \RuntimeException('Could not save the section: ' . json_encode($section->getErrors()));
        }

        return $entryType;
    }

    protected static function createEntry(string $slug, ?string $menuUid, ?string $perSiteMenuUid = null): int
    {
        $entry = new Entry();
        $entry->sectionId = Craft::$app->getEntries()->getSectionByHandle(self::SECTION_HANDLE)->id;
        $entry->typeId = self::$entryType->id;
        $entry->title = ucfirst(str_replace('-', ' ', $slug));
        $entry->slug = $slug;
        $entry->enabled = true;
        $entry->setFieldValue(self::FIELD_HANDLE, $menuUid);
        $entry->setFieldValue(self::PER_SITE_FIELD_HANDLE, $perSiteMenuUid);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new \RuntimeException("Could not save entry \"$slug\": " . json_encode($entry->getErrors()));
        }

        return (int)$entry->id;
    }

    // ---------------------------------------------------------------------
    // Assertions helpers
    // ---------------------------------------------------------------------

    /**
     * An entry query scoped to one site.
     *
     * Every entry in the fixture is enabled on both sites, and an unscoped
     * `Entry::find()` returns one row per site — so a query that forgot this
     * would report each match twice and quietly turn a "matches nothing" bug
     * into a passing test.
     */
    protected static function pages(?int $siteId = null): \craft\elements\db\EntryQuery
    {
        return Entry::find()
            ->section(self::SECTION_HANDLE)
            ->siteId($siteId ?? Craft::$app->getSites()->getPrimarySite()->id);
    }

    /** @return string[] Slugs, sorted, so assertions don't depend on query order. */
    protected static function slugsOf(array $entries): array
    {
        $slugs = array_map(fn(Entry $entry) => (string)$entry->slug, $entries);
        sort($slugs);

        return $slugs;
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fs\Local;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\Volume;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * Every link type, resolved against real Craft elements.
 *
 * `MenuBuilderVisibilityTest` and `LinkTypeResolverTest` cover the resolvers
 * against stand-ins, which is the right way to test their *rules*. It is not
 * a way to find out whether an entry's URL is really what Craft hands back
 * for it, whether a category in a group with no URLs resolves to nothing,
 * whether a soft-deleted asset takes its link with it, or whether a dynamic
 * item really queries the section it names. Those need elements, so they are
 * here.
 *
 * Builds on the shared fixture for the section and entry type it needs, and
 * adds elements of its own: nothing in this class mutates either.
 */
class MenuBuilderLinkResolutionTest extends CraftIntegrationTestCase
{
    private static bool $loaded = false;
    private static MenuBuilderGroup $menu;

    private static int $publishedEntryId;
    private static int $disabledEntryId;
    private static int $deletedEntryId;
    private static int $categoryId;
    private static int $urllessCategoryId;
    private static int $assetId;
    private static int $sectionId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$loaded) {
            return;
        }

        self::$menu = new MenuBuilderGroup();
        self::$menu->name = 'Links';
        self::$menu->handle = 'links' . bin2hex(random_bytes(4));
        MenuBuilder::getInstance()->groups->save(self::$menu);

        self::$sectionId = (int)Craft::$app->getEntries()->getSectionByHandle(self::SECTION_HANDLE)->id;
        $typeId = (int)self::$entryType->id;

        self::$publishedEntryId = self::makeEntry('link-target', $typeId, enabled: true);
        self::$disabledEntryId = self::makeEntry('link-disabled', $typeId, enabled: false);
        self::$deletedEntryId = self::makeEntry('link-doomed', $typeId, enabled: true);

        self::$categoryId = self::makeCategory('linkcats' . bin2hex(random_bytes(3)), hasUrls: true);
        self::$urllessCategoryId = self::makeCategory('nourl' . bin2hex(random_bytes(3)), hasUrls: false);

        self::$assetId = self::makeAsset();

        // Deleted last, so it is a real soft-delete of a real element rather
        // than an id that never existed.
        Craft::$app->getElements()->deleteElementById(self::$deletedEntryId);

        self::$loaded = true;
    }

    private static function makeEntry(string $slug, int $typeId, bool $enabled): int
    {
        $entry = new Entry();
        $entry->sectionId = self::$sectionId;
        $entry->typeId = $typeId;
        $entry->title = ucfirst(str_replace('-', ' ', $slug));
        $entry->slug = $slug;
        $entry->enabled = $enabled;

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new \RuntimeException("Could not save entry $slug: " . json_encode($entry->getErrors()));
        }

        return (int)$entry->id;
    }

    private static function makeCategory(string $groupHandle, bool $hasUrls): int
    {
        $siteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = new CategoryGroup_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => $hasUrls,
                'uriFormat' => $hasUrls ? $groupHandle . '/{slug}' : null,
                'template' => $hasUrls ? 'category' : null,
            ]);
        }

        $group = new CategoryGroup([
            'name' => ucfirst($groupHandle),
            'handle' => $groupHandle,
            'siteSettings' => $siteSettings,
        ]);

        if (!Craft::$app->getCategories()->saveGroup($group)) {
            throw new \RuntimeException('Could not save category group: ' . json_encode($group->getErrors()));
        }

        $category = new Category(['groupId' => $group->id]);
        $category->title = 'Boots';
        $category->slug = 'boots';

        if (!Craft::$app->getElements()->saveElement($category)) {
            throw new \RuntimeException('Could not save category: ' . json_encode($category->getErrors()));
        }

        return (int)$category->id;
    }

    private static function makeAsset(): int
    {
        // Outside the Craft install: a local filesystem is refused if it sits
        // within or above Craft's own system directories, which every path
        // under `tests/_craft` does.
        $path = sys_get_temp_dir() . '/menubuilder-test-assets';
        @mkdir($path, 0777, true);

        // The bootstrap drops the database each run, but nothing drops this
        // directory — a file left by a previous run would be a name clash
        // with the one uploaded below.
        foreach ((array)glob($path . '/*') as $stale) {
            @unlink($stale);
        }

        $fs = new Local(['handle' => 'menubuilderTest', 'name' => 'MenuBuilder Test', 'path' => $path]);
        $fs->hasUrls = true;
        $fs->url = 'https://example.test/uploads/';

        if (!Craft::$app->getFs()->saveFilesystem($fs)) {
            throw new \RuntimeException('Could not save filesystem: ' . json_encode($fs->getErrors()));
        }

        $volume = new Volume([
            'name' => 'MenuBuilder Test',
            'handle' => 'menubuilderTest',
            'fsHandle' => 'menubuilderTest',
        ]);

        if (!Craft::$app->getVolumes()->saveVolume($volume)) {
            throw new \RuntimeException('Could not save volume: ' . json_encode($volume->getErrors()));
        }

        // Staged outside the volume's own directory: an upload whose source
        // file already sits in the destination folder is refused as a clash
        // with itself.
        $staging = sys_get_temp_dir() . '/menubuilder-test-staging';
        @mkdir($staging, 0777, true);
        $file = $staging . '/brochure.pdf';
        file_put_contents($file, '%PDF-1.4 test');

        $asset = new Asset();
        $asset->tempFilePath = $file;
        $asset->setFilename('brochure.pdf');
        $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId((int)$volume->id)->id;
        $asset->setVolumeId((int)$volume->id);
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new \RuntimeException('Could not save asset: ' . json_encode($asset->getErrors()));
        }

        return (int)$asset->id;
    }

    /** An unsaved item, so resolution is tested without a write per case. */
    private function item(string $type, ?callable $configure = null): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = (int)self::$menu->id;
        $item->type = $type;
        $item->title = 'Item';

        if ($configure) {
            $configure($item);
        }

        return $item;
    }

    private function resolve(MenuBuilderItem $item): \Tahadudhiya\MenuBuilder\models\ResolvedLink
    {
        return MenuBuilder::getInstance()->linkResolver->resolve($item);
    }

    // ---------------------------------------------------------------------
    // Entry
    // ---------------------------------------------------------------------

    public function testAnEntryItemResolvesToTheEntrysOwnUrlAndTitle(): void
    {
        $entry = Craft::$app->getElements()->getElementById(self::$publishedEntryId, Entry::class);

        $link = $this->resolve($this->item(
            MenuBuilderItem::TYPE_ENTRY,
            fn(MenuBuilderItem $i) => $i->elementId = self::$publishedEntryId,
        ));

        $this->assertTrue($link->isAvailable);
        $this->assertSame($entry->getUrl(), $link->url);
        $this->assertSame($entry->title, $link->label);
    }

    public function testADisabledEntryIsUnavailable(): void
    {
        $link = $this->resolve($this->item(
            MenuBuilderItem::TYPE_ENTRY,
            fn(MenuBuilderItem $i) => $i->elementId = self::$disabledEntryId,
        ));

        $this->assertFalse($link->isAvailable);
        $this->assertNull($link->url);
    }

    public function testADeletedEntryIsUnavailable(): void
    {
        $link = $this->resolve($this->item(
            MenuBuilderItem::TYPE_ENTRY,
            fn(MenuBuilderItem $i) => $i->elementId = self::$deletedEntryId,
        ));

        $this->assertFalse($link->isAvailable);
    }

    public function testAnEntryIdThatNeverExistedIsUnavailable(): void
    {
        $link = $this->resolve($this->item(
            MenuBuilderItem::TYPE_ENTRY,
            fn(MenuBuilderItem $i) => $i->elementId = 999999,
        ));

        $this->assertFalse($link->isAvailable);
    }

    /**
     * `fallbackUrl` is what an editor sets so a menu does not lose a slot
     * when the element behind it goes away — it has to be reached from a real
     * missing element, not only from a stubbed one.
     */
    public function testAMissingEntryFallsBackToTheConfiguredUrl(): void
    {
        $link = $this->resolve($this->item(MenuBuilderItem::TYPE_ENTRY, function(MenuBuilderItem $i) {
            $i->elementId = self::$deletedEntryId;
            $i->fallbackBehavior = MenuBuilderItem::FALLBACK_FALLBACK_URL;
            $i->fallbackUrl = '/replacement';
        }));

        $this->assertTrue($link->isAvailable);
        $this->assertSame('/replacement', $link->url);
    }

    public function testAMissingEntryWithTheDisableLinkFallbackKeepsTheItemWithoutAUrl(): void
    {
        $link = $this->resolve($this->item(MenuBuilderItem::TYPE_ENTRY, function(MenuBuilderItem $i) {
            $i->elementId = self::$deletedEntryId;
            $i->fallbackBehavior = MenuBuilderItem::FALLBACK_DISABLE_LINK;
        }));

        $this->assertTrue($link->isAvailable);
        $this->assertNull($link->url);
    }

    // ---------------------------------------------------------------------
    // Category
    // ---------------------------------------------------------------------

    public function testACategoryItemResolvesToTheCategorysUrl(): void
    {
        $category = Craft::$app->getElements()->getElementById(self::$categoryId, Category::class);

        $link = $this->resolve($this->item(
            MenuBuilderItem::TYPE_CATEGORY,
            fn(MenuBuilderItem $i) => $i->elementId = self::$categoryId,
        ));

        $this->assertTrue($link->isAvailable);
        $this->assertSame($category->getUrl(), $link->url);
        $this->assertStringContainsString('boots', (string)$link->url);
    }

    /**
     * A category group with no URI format produces categories with no URL at
     * all. Craft returns `null` rather than an error, so the resolver has to
     * treat "exists but is not addressable" as unavailable rather than
     * emitting an empty `href`.
     */
    public function testACategoryInAGroupWithNoUrlsIsUnavailable(): void
    {
        $link = $this->resolve($this->item(
            MenuBuilderItem::TYPE_CATEGORY,
            fn(MenuBuilderItem $i) => $i->elementId = self::$urllessCategoryId,
        ));

        $this->assertFalse($link->isAvailable);
        $this->assertNull($link->url);
    }

    // ---------------------------------------------------------------------
    // Asset
    // ---------------------------------------------------------------------

    public function testAnAssetItemResolvesToTheFilesUrl(): void
    {
        $asset = Craft::$app->getElements()->getElementById(self::$assetId, Asset::class);

        $link = $this->resolve($this->item(
            MenuBuilderItem::TYPE_ASSET,
            fn(MenuBuilderItem $i) => $i->elementId = self::$assetId,
        ));

        $this->assertTrue($link->isAvailable);
        $this->assertSame($asset->getUrl(), $link->url);
        $this->assertStringEndsWith('brochure.pdf', (string)$link->url);
    }

    public function testTheAssetsFilenameIsTheLabelWhenTheItemHasNoTitle(): void
    {
        $item = $this->item(MenuBuilderItem::TYPE_ASSET, function(MenuBuilderItem $i) {
            $i->elementId = self::$assetId;
            $i->title = '';
        });

        $this->assertNotEmpty($this->resolve($item)->label);
    }

    // ---------------------------------------------------------------------
    // URL
    // ---------------------------------------------------------------------

    /** @dataProvider urlProvider */
    public function testAUrlItemResolvesToWhatWasTyped(string $url, bool $available): void
    {
        $link = $this->resolve($this->item(
            MenuBuilderItem::TYPE_URL,
            fn(MenuBuilderItem $i) => $i->customUrl = $url,
        ));

        $this->assertSame($available, $link->isAvailable);

        if ($available) {
            $this->assertSame($url, $link->url);
        }
    }

    /** @return array<string,array{string,bool}> */
    public static function urlProvider(): array
    {
        return [
            'relative' => ['/about', true],
            'absolute' => ['https://example.com/about', true],
            'mailto' => ['mailto:hello@example.com', true],
            'tel' => ['tel:+441234567890', true],
            'homepage' => ['/', true],
            'javascript' => ['javascript:alert(1)', false],
            'data' => ['data:text/html,<script>alert(1)</script>', false],
            'empty' => ['', false],
        ];
    }

    // ---------------------------------------------------------------------
    // Anchor
    // ---------------------------------------------------------------------

    public function testAnAnchorItemResolvesToAFragment(): void
    {
        $link = $this->resolve($this->item(
            MenuBuilderItem::TYPE_ANCHOR,
            fn(MenuBuilderItem $i) => $i->customUrl = '#pricing',
        ));

        $this->assertTrue($link->isAvailable);
        $this->assertSame('#pricing', $link->url);
    }

    // ---------------------------------------------------------------------
    // Structural types
    // ---------------------------------------------------------------------

    public function testANonClickableItemResolvesToNoLinkButStaysAvailable(): void
    {
        $link = $this->resolve($this->item(MenuBuilderItem::TYPE_NONCLICKABLE));

        $this->assertTrue($link->isAvailable);
        $this->assertNull($link->url);
    }

    public function testASeparatorResolvesToNoLinkButStaysAvailable(): void
    {
        $link = $this->resolve($this->item(MenuBuilderItem::TYPE_SEPARATOR));

        $this->assertTrue($link->isAvailable);
        $this->assertNull($link->url);
    }

    /** A structural item is not made clickable by a URL left in the column. */
    public function testAStructuralItemIgnoresALeftoverUrl(): void
    {
        $link = $this->resolve($this->item(
            MenuBuilderItem::TYPE_NONCLICKABLE,
            fn(MenuBuilderItem $i) => $i->customUrl = '/was-a-link',
        ));

        $this->assertNull($link->url);
    }

    // ---------------------------------------------------------------------
    // Dynamic
    // ---------------------------------------------------------------------

    public function testADynamicItemQueriesTheSectionItNamesAndSkipsDisabledEntries(): void
    {
        $elements = MenuBuilder::getInstance()->dynamicNavigation->resolveElements([
            'sourceType' => 'entries',
            'sourceId' => self::$sectionId,
            'limit' => 50,
            'orderBy' => 'title',
        ]);

        $ids = array_map(fn($element) => (int)$element->id, $elements);

        $this->assertContains(self::$publishedEntryId, $ids);
        $this->assertNotContains(self::$disabledEntryId, $ids);
        $this->assertNotContains(self::$deletedEntryId, $ids);
    }

    public function testADynamicItemHonoursItsLimit(): void
    {
        $elements = MenuBuilder::getInstance()->dynamicNavigation->resolveElements([
            'sourceType' => 'entries',
            'sourceId' => self::$sectionId,
            'limit' => 1,
            'orderBy' => 'title',
        ]);

        $this->assertCount(1, $elements);
    }

    public function testADynamicItemNamingASectionThatDoesNotExistYieldsNothing(): void
    {
        $this->assertSame([], MenuBuilder::getInstance()->dynamicNavigation->resolveElements([
            'sourceType' => 'entries',
            'sourceId' => 999999,
            'limit' => 50,
            'orderBy' => 'title',
        ]));
    }

    public function testAMalformedDynamicConfigYieldsNothingRatherThanEverything(): void
    {
        $this->assertSame([], MenuBuilder::getInstance()->dynamicNavigation->resolveElements([
            'sourceType' => 'nonsense',
            'sourceId' => self::$sectionId,
        ]));
    }

    public function testADynamicCategorySourceQueriesTheCategoryGroup(): void
    {
        $groupId = (int)Craft::$app->getElements()
            ->getElementById(self::$categoryId, Category::class)
            ->groupId;

        $elements = MenuBuilder::getInstance()->dynamicNavigation->resolveElements([
            'sourceType' => 'categories',
            'sourceId' => $groupId,
            'limit' => 50,
            'orderBy' => 'title',
        ]);

        $this->assertSame([self::$categoryId], array_map(fn($e) => (int)$e->id, $elements));
    }
}

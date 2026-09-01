<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use Tahadudhiya\MenuBuilder\fields\MenuBuilderField;
use Tahadudhiya\MenuBuilder\models\MenuBuilderFieldValue;
use Tahadudhiya\MenuBuilder\models\MenuBuilderTree;

/**
 * The Navigation field against a **real booted Craft 5 application and a real
 * database**: does a selection actually round-trip through Craft's content
 * storage, and does `self::pages()->navigation($uid)` compile to a query that
 * matches it?
 *
 * The unit suite pins the field's *decisions* — what a value may be, when a
 * selection is invalid, what Twig gets. None of that proves the field is
 * wired into Craft correctly: a field with a wrong `dbType()`, a
 * `serializeValue()` that returns the wrong shape, or a `valueSql()` Craft
 * can't build a condition from would pass every unit test and still store
 * nothing and match nothing. That is what this file covers.
 */
class MenuBuilderFieldQueryTest extends CraftIntegrationTestCase
{
    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    /**
     * Craft 5 keeps custom field values in the `elements_sites.content` JSON
     * column, keyed by the field **layout element's** UID (which is what lets
     * one field appear twice in a layout). Asserting the raw column — rather
     * than just reading the value back through the same field that wrote it —
     * is what proves the value is really in Craft's content storage and not in
     * some side channel of the field's own making.
     */
    public function testTheSelectedUidIsStoredInCraftsContentColumn(): void
    {
        $entry = self::pages()->id(self::$entryIds['picks-main'])->one();

        $content = (new \craft\db\Query())
            ->select(['content'])
            ->from('{{%elements_sites}}')
            ->where(['elementId' => $entry->id, 'siteId' => $entry->siteId])
            ->scalar();

        $decoded = json_decode((string)$content, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey(
            self::$fieldInstanceUid,
            $decoded,
            'Field values are keyed by the field layout element’s UID in Craft 5.'
        );
        $this->assertSame(
            (string)self::$mainMenu->uid,
            $decoded[self::$fieldInstanceUid],
            'The stored value is the menu’s UID — not its handle and not its row ID.'
        );
    }

    public function testAValueRoundTripsBackAsTheValueObject(): void
    {
        $entry = self::pages()->id(self::$entryIds['picks-main'])->one();
        $value = $entry->getFieldValue(self::FIELD_HANDLE);

        $this->assertInstanceOf(MenuBuilderFieldValue::class, $value);
        $this->assertSame((string)self::$mainMenu->uid, $value->groupUid);
        $this->assertSame('main', $value->getHandle());
        $this->assertSame('Main Navigation', $value->getName());
        $this->assertTrue($value->exists());
    }

    public function testAnEmptySelectionRoundTripsAsNull(): void
    {
        $entry = self::pages()->id(self::$entryIds['picks-nothing'])->one();

        $this->assertNull(
            $entry->getFieldValue(self::FIELD_HANDLE),
            '{% if entry.navigation %} has to answer “nothing selected” correctly.'
        );
    }

    /**
     * The end-to-end promise: a real entry, read back from a real database,
     * resolves a real menu through the real resolver.
     */
    public function testAStoredSelectionResolvesARealTree(): void
    {
        $entry = self::pages()->id(self::$entryIds['picks-main'])->one();
        /** @var MenuBuilderFieldValue $value */
        $value = $entry->getFieldValue(self::FIELD_HANDLE);

        $tree = $value->getTree();

        $this->assertInstanceOf(MenuBuilderTree::class, $tree);
        $this->assertSame('main', $tree->group->handle);
        $this->assertSame(['Home', 'About'], array_map(fn($node) => $node->title, $tree->items));
        $this->assertCount(2, $value, 'The value iterates the resolved tree’s top-level nodes.');
    }

    /** Changing the selection overwrites the stored UID rather than accumulating. */
    public function testChangingTheSelectionOverwritesTheStoredValue(): void
    {
        $entry = self::pages()->id(self::$entryIds['picks-footer'])->one();
        $entry->setFieldValue(self::FIELD_HANDLE, (string)self::$mainMenu->uid);

        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $reloaded = self::pages()->id(self::$entryIds['picks-footer'])->one();
        $this->assertSame((string)self::$mainMenu->uid, $reloaded->getFieldValue(self::FIELD_HANDLE)->groupUid);

        // Put the fixture back, so test order can't matter.
        $reloaded->setFieldValue(self::FIELD_HANDLE, (string)self::$footerMenu->uid);
        $this->assertTrue(Craft::$app->getElements()->saveElement($reloaded));
        $this->assertSame(
            (string)self::$footerMenu->uid,
            self::pages()->id(self::$entryIds['picks-footer'])->one()->getFieldValue(self::FIELD_HANDLE)->groupUid
        );
    }

    // ---------------------------------------------------------------------
    // Query API
    // ---------------------------------------------------------------------

    public function testQueryingByUidMatchesTheEntriesThatSelectedIt(): void
    {
        $entries = self::pages()->navigation((string)self::$mainMenu->uid)->all();

        $this->assertSame(['picks-main', 'picks-main-too'], self::slugsOf($entries));
    }

    public function testQueryingByUidReturnsIdsToo(): void
    {
        $ids = self::pages()->navigation((string)self::$mainMenu->uid)->ids();

        sort($ids);
        $expected = [self::$entryIds['picks-main'], self::$entryIds['picks-main-too']];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function testQueryingByADifferentUidMatchesADifferentEntry(): void
    {
        $this->assertSame(
            ['picks-footer'],
            self::slugsOf(self::pages()->navigation((string)self::$footerMenu->uid)->all())
        );
    }

    public function testQueryingByAUidNoEntryUsesMatchesNothing(): void
    {
        $this->assertSame([], self::pages()->navigation(StringHelper::UUID())->all());
        $this->assertSame([], self::pages()->navigation(StringHelper::UUID())->ids());
    }

    /**
     * A deleted menu leaves its UID behind in every entry that selected it.
     * That is deliberate — the value is what lets the CP say "the navigation
     * this was set to no longer exists" instead of silently reading as empty
     * — so the query has to keep matching it.
     */
    public function testQueryingByADeletedMenusUidStillMatchesTheEntriesHoldingIt(): void
    {
        $this->assertSame(
            ['picks-doomed'],
            self::slugsOf(self::pages()->navigation(self::$deletedMenuUid)->all())
        );

        $entry = self::pages()->id(self::$entryIds['picks-doomed'])->one();
        /** @var MenuBuilderFieldValue $value */
        $value = $entry->getFieldValue(self::FIELD_HANDLE);

        $this->assertSame(self::$deletedMenuUid, $value->groupUid, 'The selection outlives the menu…');
        $this->assertFalse($value->exists(), '…and says so.');
        $this->assertNull($value->getTree());
        $this->assertCount(0, $value);
    }

    /** A disabled menu is a perfectly normal stored selection. */
    public function testQueryingByADisabledMenusUidMatches(): void
    {
        $this->assertSame(
            ['picks-disabled'],
            self::slugsOf(self::pages()->navigation((string)self::$disabledMenu->uid)->all())
        );

        /** @var MenuBuilderFieldValue $value */
        $value = self::pages()->id(self::$entryIds['picks-disabled'])->one()->getFieldValue(self::FIELD_HANDLE);

        $this->assertTrue($value->exists());
        $this->assertFalse($value->isEnabled());
        $this->assertNull($value->getTree(), 'A disabled menu renders nothing.');
    }

    public function testQueryingForAnEmptySelection(): void
    {
        $this->assertSame(
            ['picks-nothing'],
            self::slugsOf(self::pages()->navigation(':empty:')->all())
        );
    }

    public function testQueryingForAnyNonEmptySelection(): void
    {
        $slugs = self::slugsOf(self::pages()->navigation('not :empty:')->all());

        $this->assertNotContains('picks-nothing', $slugs);
        $this->assertContains('picks-main', $slugs);
        $this->assertContains('picks-doomed', $slugs);
    }

    public function testQueryingByEitherOfTwoUids(): void
    {
        $slugs = self::slugsOf(
            self::pages()->navigation(['or', (string)self::$mainMenu->uid, (string)self::$footerMenu->uid])->all()
        );

        $this->assertSame(['picks-footer', 'picks-main', 'picks-main-too'], $slugs);
    }

    /**
     * The condition has to be built by Craft's own content-query pipeline —
     * `Field::queryCondition()` over `valueSql()`, reading the JSON content
     * column — and not by anything MenuBuilder invented. If a future change
     * broke that wiring, the queries above would still "pass" by matching
     * nothing, so the generated SQL is asserted directly.
     */
    public function testTheQueryUsesCraftsContentColumnPipeline(): void
    {
        $query = self::pages()->navigation((string)self::$mainMenu->uid);
        $prepared = $query->prepare(Craft::$app->getDb()->getQueryBuilder());
        [$sql] = Craft::$app->getDb()->getQueryBuilder()->build($prepared);

        $this->assertStringContainsString(
            'elements_sites`.`content',
            $sql,
            'Values are read out of Craft’s content column, by Craft’s own query builder.'
        );
        $this->assertStringContainsString(
            self::$fieldInstanceUid,
            $sql,
            'Addressed by the field layout element’s UID, as Craft 5 stores them.'
        );
        $this->assertMatchesRegularExpression(
            '/CAST\(.*content.*AS CHAR\(255\)\)/s',
            $sql,
            'dbType() string is what makes Craft cast the JSON value for comparison and indexing.'
        );
    }

    /** `dbType()` decides the column shape Craft builds the condition against. */
    public function testTheFieldDeclaresAStringValueToCraft(): void
    {
        $this->assertSame('string', MenuBuilderField::dbType());
        // Only a field *instance in a layout* can produce value SQL — a bare
        // field has no content key to address.
        $this->assertNotNull(
            self::$fieldInstance->getValueSql(),
            'Without value SQL, Craft cannot query the field at all.'
        );
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\Tests\Integration;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\MenuBuilder\elements\MenuBuilderItemContent;
use Tahadudhiya\MenuBuilder\MenuBuilder;
use Tahadudhiya\MenuBuilder\models\MenuBuilderGroup;
use Tahadudhiya\MenuBuilder\models\MenuBuilderItem;

/**
 * Menu items' custom fields, end to end against the real database.
 *
 * These are Craft fields on a real field layout now, stored on a
 * {@see MenuBuilderItemContent} element beside each item — so the questions
 * worth asking are the ones only a database answers: whether a value
 * round-trips, whether a duplicated item gets its own content rather than
 * sharing the original's, whether deleting an item takes its content, and
 * whether the `parentId` cascade — which removes rows without passing
 * through PHP at all — leaves anything behind that garbage collection
 * doesn't sweep.
 *
 * Each test works in a menu of its own, deleted afterwards.
 */
class MenuBuilderItemContentTest extends TestCase
{
    private const FIELD_HANDLE = 'mbTestSubtitle';

    private MenuBuilderGroup $menu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->menu = new MenuBuilderGroup();
        $this->menu->name = 'Item content';
        $this->menu->handle = 'itemContent' . bin2hex(random_bytes(4));
        $this->menu->setFieldLayout($this->layoutWithTestField());

        $this->assertTrue(MenuBuilder::getInstance()->groups->save($this->menu), json_encode($this->menu->getErrors()));
    }

    protected function tearDown(): void
    {
        MenuBuilder::getInstance()->groups->deleteById((int)$this->menu->id);

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // The layout
    // ---------------------------------------------------------------------

    public function testAMenuWithFieldsReportsThemAndAMenuWithoutDoesNot(): void
    {
        $this->assertTrue($this->reloadMenu()->hasCustomFields());

        $plain = new MenuBuilderGroup();
        $plain->name = 'No fields';
        $plain->handle = 'noFields' . bin2hex(random_bytes(4));
        $this->assertTrue(MenuBuilder::getInstance()->groups->save($plain));

        // An empty layout is never given a row: `hasCustomFields()` would
        // otherwise be true for every menu ever created.
        $this->assertNull($plain->fieldLayoutId);
        $this->assertFalse($plain->hasCustomFields());

        MenuBuilder::getInstance()->groups->deleteById((int)$plain->id);
    }

    /**
     * Emptying a menu's layout deletes the row rather than leaving one
     * holding nothing — the state a menu that never had fields is in.
     */
    public function testEmptyingAMenusLayoutRemovesIt(): void
    {
        $menu = $this->reloadMenu();
        $fieldLayoutId = (int)$menu->fieldLayoutId;

        $menu->setFieldLayout(new FieldLayout(['type' => MenuBuilderItemContent::class]));
        $this->assertTrue(MenuBuilder::getInstance()->groups->save($menu));

        $this->assertNull($this->reloadMenu()->fieldLayoutId);
        $this->assertFalse($this->fieldLayoutExists($fieldLayoutId));
    }

    // ---------------------------------------------------------------------
    // Values
    // ---------------------------------------------------------------------

    public function testAFieldValueRoundTripsThroughTheContentElement(): void
    {
        $item = $this->addItem('Home', 'Since 1998');

        $this->assertNotNull($item->contentId);

        $reloaded = MenuBuilder::getInstance()->items->getById((int)$item->id);

        $this->assertSame((int)$item->contentId, (int)$reloaded->contentId);
        $this->assertSame('Since 1998', $reloaded->customFieldValue(self::FIELD_HANDLE));
    }

    /**
     * An item in a menu with no field layout gets no content element at all
     * — an install that uses no custom fields must never write a row to
     * `elements`.
     */
    public function testAnItemInAMenuWithoutFieldsGetsNoContentElement(): void
    {
        $plain = new MenuBuilderGroup();
        $plain->name = 'No fields';
        $plain->handle = 'noFields' . bin2hex(random_bytes(4));
        $this->assertTrue(MenuBuilder::getInstance()->groups->save($plain));

        $item = new MenuBuilderItem();
        $item->groupId = (int)$plain->id;
        $item->title = 'Plain';
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = '/plain';

        $this->assertTrue(MenuBuilder::getInstance()->items->save($item));
        $this->assertNull($item->contentId);
        $this->assertNull($item->getContent());

        MenuBuilder::getInstance()->groups->deleteById((int)$plain->id);
    }

    /** A handle the menu's layout doesn't define reads as null, never as an error. */
    public function testAnUnknownHandleReadsAsNull(): void
    {
        $item = $this->addItem('Home', 'Since 1998');

        $this->assertNull(
            MenuBuilder::getInstance()->items->getById((int)$item->id)->customFieldValue('notAField'),
        );
    }

    // ---------------------------------------------------------------------
    // Duplication
    // ---------------------------------------------------------------------

    public function testDuplicatingAnItemGivesTheCopyItsOwnContent(): void
    {
        $item = $this->addItem('Home', 'Since 1998');
        $clone = MenuBuilder::getInstance()->items->duplicate((int)$item->id);

        $this->assertNotNull($clone->contentId);
        $this->assertNotSame((int)$item->contentId, (int)$clone->contentId);
        $this->assertSame('Since 1998', $clone->customFieldValue(self::FIELD_HANDLE));

        // Editing the copy must not reach back into the original.
        $clone->getContent()->setFieldValue(self::FIELD_HANDLE, 'Rewritten');
        $this->assertTrue(MenuBuilder::getInstance()->items->save($clone));

        $this->assertSame(
            'Since 1998',
            MenuBuilder::getInstance()->items->getById((int)$item->id)->customFieldValue(self::FIELD_HANDLE),
        );
    }

    // ---------------------------------------------------------------------
    // Deletion
    // ---------------------------------------------------------------------

    public function testDeletingAnItemDeletesItsContentElement(): void
    {
        $item = $this->addItem('Home', 'Since 1998');
        $contentId = (int)$item->contentId;

        $this->assertTrue(MenuBuilder::getInstance()->items->deleteById((int)$item->id));
        $this->assertFalse($this->elementExists($contentId));
    }

    /**
     * The `parentId` cascade removes a subtree inside the database, so the
     * content of every descendant has to be collected *before* the delete
     * runs — after it, there is no row left naming any of them.
     */
    public function testDeletingAParentDeletesItsDescendantsContentToo(): void
    {
        $parent = $this->addItem('Products', 'Parent copy');
        $child = $this->addItem('Widgets', 'Child copy', (int)$parent->id);
        $grandchild = $this->addItem('Blue', 'Grandchild copy', (int)$child->id);

        $contentIds = [(int)$parent->contentId, (int)$child->contentId, (int)$grandchild->contentId];
        $this->assertCount(3, array_unique($contentIds));

        $this->assertTrue(MenuBuilder::getInstance()->items->deleteById((int)$parent->id));

        foreach ($contentIds as $contentId) {
            $this->assertFalse($this->elementExists($contentId), "Content element $contentId survived.");
        }
    }

    /** Deleting a menu takes every item's content with it. */
    public function testDeletingAMenuDeletesEveryItemsContent(): void
    {
        $first = $this->addItem('One', 'First');
        $second = $this->addItem('Two', 'Second');
        $contentIds = [(int)$first->contentId, (int)$second->contentId];

        MenuBuilder::getInstance()->groups->deleteById((int)$this->menu->id);

        foreach ($contentIds as $contentId) {
            $this->assertFalse($this->elementExists($contentId));
        }

        // tearDown() deletes it again; a second delete of a missing menu is
        // a no-op, so nothing here depends on that ordering.
    }

    /**
     * The backstop for the one path PHP can't see: a content element
     * stranded by a row that vanished with the cascade is swept by garbage
     * collection.
     */
    public function testGarbageCollectionSweepsStrandedContent(): void
    {
        $item = $this->addItem('Home', 'Since 1998');
        $contentId = (int)$item->contentId;

        // Exactly what the cascade does — delete the item row, and only the
        // item row, behind the plugin's back.
        Craft::$app->getDb()->createCommand()
            ->delete('{{%menubuilder_items}}', ['id' => $item->id])
            ->execute();

        $this->assertTrue($this->elementExists($contentId));

        Craft::$app->getGc()->run(true);

        $this->assertFalse($this->elementExists($contentId));
    }

    // ---------------------------------------------------------------------
    // The read surfaces (Twig, GraphQL, REST)
    // ---------------------------------------------------------------------

    /**
     * `hasValueFor()` asks the *field* whether its value is empty rather
     * than comparing to null, which is the only answer that holds for field
     * types whose empty value is not null — and it is what a template's
     * `node.hasCustom()` is deciding a whole markup branch on.
     */
    public function testHasValueForDistinguishesEmptyFromAbsent(): void
    {
        $filled = $this->addItem('Home', 'Since 1998');
        $blank = $this->addItem('About', '');

        $content = MenuBuilder::getInstance()->itemContent;

        $this->assertTrue($content->hasValueFor((int)$filled->contentId, self::FIELD_HANDLE));
        $this->assertFalse($content->hasValueFor((int)$blank->contentId, self::FIELD_HANDLE));

        // An unknown handle and a null content id are both "no value", not
        // an exception: a live page must survive a field the menu has since
        // lost.
        $this->assertFalse($content->hasValueFor((int)$filled->contentId, 'noSuchHandle'));
        $this->assertFalse($content->hasValueFor(null, self::FIELD_HANDLE));
    }

    /**
     * `handlesFor()` is what "every custom field on this node" iterates when
     * nothing names the fields — the GraphQL field list and the Twig loop.
     */
    public function testHandlesForListsTheMenusFieldsAndNothingElse(): void
    {
        $item = $this->addItem('Home', 'Since 1998');

        $this->assertSame(
            [self::FIELD_HANDLE],
            MenuBuilder::getInstance()->itemContent->handlesFor((int)$item->contentId),
        );

        $this->assertSame([], MenuBuilder::getInstance()->itemContent->handlesFor(null));
    }

    /**
     * The wire shape: serialized values, keyed by handle, for every field on
     * the layout — including the ones the item left empty, so a client gets
     * a stable set of keys rather than one that varies per item.
     */
    public function testSerializedValuesForReturnsEveryFieldOnTheLayout(): void
    {
        $filled = $this->addItem('Home', 'Since 1998');
        $blank = $this->addItem('About', '');

        $content = MenuBuilder::getInstance()->itemContent;

        $this->assertSame(
            [self::FIELD_HANDLE => 'Since 1998'],
            $content->serializedValuesFor((int)$filled->contentId),
        );

        $this->assertSame([self::FIELD_HANDLE], array_keys($content->serializedValuesFor((int)$blank->contentId)));
        $this->assertSame([], $content->serializedValuesFor(null));
    }

    /**
     * A content element whose row is gone must read as "no value" forever
     * after, without going back to the database each time — that record of
     * absence is what stops a stale cached `contentId` turning one dead
     * reference into a query per node per request.
     */
    public function testAMissingContentElementReadsAsTheDefault(): void
    {
        $item = $this->addItem('Home', 'Since 1998');
        $contentId = (int)$item->contentId;

        MenuBuilder::getInstance()->items->deleteById((int)$item->id);

        $content = MenuBuilder::getInstance()->itemContent;

        $this->assertSame('fallback', $content->valueFor($contentId, self::FIELD_HANDLE, 'fallback'));
        $this->assertFalse($content->hasValueFor($contentId, self::FIELD_HANDLE));
        $this->assertSame([], $content->handlesFor($contentId));
    }

    /**
     * `preload()` is the resolver's promise that a whole tree's values cost
     * one query, not one per node. Registering ids is idempotent and reading
     * them afterwards must give the same answers as reading them cold.
     */
    public function testPreloadedContentReadsTheSameAsUnpreloaded(): void
    {
        $one = $this->addItem('Home', 'Since 1998');
        $two = $this->addItem('About', 'Est. 2004');

        $content = MenuBuilder::getInstance()->itemContent;
        $ids = [(int)$one->contentId, (int)$two->contentId];

        $content->preload($ids);
        $content->preload($ids); // idempotent
        $content->preload([0, -1]); // ignored rather than queried

        $this->assertSame('Since 1998', $content->valueFor($ids[0], self::FIELD_HANDLE));
        $this->assertSame('Est. 2004', $content->valueFor($ids[1], self::FIELD_HANDLE));
    }

    /**
     * Two menus, two layouts: an item's content is rendered against the menu
     * it belongs to, not against whichever layout its `elements` row happens
     * to name. This is the case the dev site exercises with `mainNav` and
     * `footerNav` holding different field sets.
     */
    public function testContentIsRepointedAtItsOwnMenusLayout(): void
    {
        $other = new MenuBuilderGroup();
        $other->name = 'Other fields';
        $other->handle = 'otherFields' . bin2hex(random_bytes(4));
        $other->setFieldLayout($this->layoutWithTestField());
        $this->assertTrue(MenuBuilder::getInstance()->groups->save($other));

        try {
            $item = $this->addItem('Home', 'Since 1998');

            // Point the stored element at the *other* menu's layout, as a
            // layout replaced wholesale would.
            Craft::$app->getDb()->createCommand()
                ->update(Table::ELEMENTS, ['fieldLayoutId' => $other->fieldLayoutId], ['id' => $item->contentId])
                ->execute();

            $reloaded = MenuBuilder::getInstance()->items->getById((int)$item->id);

            $this->assertSame(
                (int)$this->reloadMenu()->fieldLayoutId,
                (int)$reloaded->getContent()->fieldLayoutId,
            );
        } finally {
            MenuBuilder::getInstance()->groups->deleteById((int)$other->id);
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function addItem(string $title, string $subtitle, ?int $parentId = null): MenuBuilderItem
    {
        $item = new MenuBuilderItem();
        $item->groupId = (int)$this->menu->id;
        $item->parentId = $parentId;
        $item->title = $title;
        $item->type = MenuBuilderItem::TYPE_URL;
        $item->customUrl = '/' . strtolower(str_replace(' ', '-', $title));

        $content = $item->getContent();
        $this->assertNotNull($content, 'The menu has fields, so its items must have content.');
        $content->setFieldValue(self::FIELD_HANDLE, $subtitle);
        $item->setContent($content);

        $this->assertTrue(MenuBuilder::getInstance()->items->save($item), json_encode($item->getErrors()));

        return $item;
    }

    private function reloadMenu(): MenuBuilderGroup
    {
        return MenuBuilder::getInstance()->groups->getById((int)$this->menu->id);
    }

    /** Hard existence, including soft-deleted rows — this element is never soft-deleted. */
    private function elementExists(int $elementId): bool
    {
        return (new Query())->from(Table::ELEMENTS)->where(['id' => $elementId])->exists();
    }

    /**
     * Asked of the table, not of `Fields::getLayoutById()`: that memoizes,
     * so a layout deleted in this request still comes back from it. Craft
     * soft-deletes layouts, so "gone" means `dateDeleted` is set.
     */
    private function fieldLayoutExists(int $fieldLayoutId): bool
    {
        return (new Query())
            ->from(Table::FIELDLAYOUTS)
            ->where(['id' => $fieldLayoutId, 'dateDeleted' => null])
            ->exists();
    }

    private function layoutWithTestField(): FieldLayout
    {
        $field = Craft::$app->getFields()->getFieldByHandle(self::FIELD_HANDLE);

        if ($field === null) {
            $field = new PlainText(['name' => 'MB Test Subtitle', 'handle' => self::FIELD_HANDLE]);
            $this->assertTrue(Craft::$app->getFields()->saveField($field), 'Could not create the test field.');
        }

        $layout = new FieldLayout(['type' => MenuBuilderItemContent::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new CustomField($field)]);
        $layout->setTabs([$tab]);

        return $layout;
    }
}

<?php

namespace Tahadudhiya\MenuBuilder\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%menubuilder_groups}}')) {
            $this->createTable('{{%menubuilder_groups}}', [
                'id' => $this->primaryKey(),
                'name' => $this->string(255)->notNull(),
                'handle' => $this->string(255)->notNull(),
                'description' => $this->text(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'maxDepth' => $this->tinyInteger(),
                'cssClass' => $this->string(255),
                'htmlAttributes' => $this->text()->notNull(),
                'settings' => $this->text()->notNull(),
                // The menu's Craft field layout — the fields every item in
                // it is offered. Null until the editor adds one; see
                // elements/MenuBuilderItemContent for the split between the
                // layout (per menu) and its content (per item).
                'fieldLayoutId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%menubuilder_groups}}', ['handle'], true);
            $this->createIndex(null, '{{%menubuilder_groups}}', ['fieldLayoutId'], false);

            // SET NULL, not CASCADE: deleting a field layout must never take
            // the menu with it. A menu without a layout is a menu without
            // extra fields, which is the default state anyway.
            //
            // Guarded on the target's existence because this is the one
            // foreign key that leaves the plugin's own schema. In a real
            // install Craft's tables are always there, so the constraint is
            // always created; the guard only matters where this migration is
            // exercised against a schema of its own (the install test runs
            // it under a throwaway table prefix), and an install that
            // silently failed there would be an install nobody could test.
            $this->addCraftForeignKey('{{%menubuilder_groups}}', 'fieldLayoutId', Table::FIELDLAYOUTS);
        }

        if (!$this->db->tableExists('{{%menubuilder_items}}')) {
            $this->createTable('{{%menubuilder_items}}', [
                'id' => $this->primaryKey(),
                'groupId' => $this->integer()->notNull(),
                'parentId' => $this->integer(),
                'type' => $this->string(20)->notNull()->defaultValue('url'),
                'title' => $this->string(255)->notNull()->defaultValue(''),
                'handle' => $this->string(255),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'clickable' => $this->boolean()->notNull()->defaultValue(true),
                'elementId' => $this->integer(),
                'customUrl' => $this->text(),
                'target' => $this->string(10)->notNull()->defaultValue('_self'),
                'rel' => $this->string(255),
                'cssClass' => $this->string(255),
                'htmlId' => $this->string(255),
                'htmlAttributes' => $this->text()->notNull(),
                'ariaLabel' => $this->string(255),
                'titleAttribute' => $this->string(255),
                'icon' => $this->string(255),
                'badge' => $this->string(255),
                'description' => $this->text(),
                'image' => $this->integer(),
                'featured' => $this->boolean()->notNull()->defaultValue(false),
                'fallbackBehavior' => $this->string(20)->notNull()->defaultValue('hide'),
                'fallbackUrl' => $this->text(),
                'visibility' => $this->text()->notNull(),
                'metadata' => $this->text()->notNull(),
                // The `elements` row carrying this item's field layout
                // content. Null until the owning menu has a field layout.
                'contentId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%menubuilder_items}}', ['groupId', 'parentId', 'sortOrder'], false);
            $this->createIndex(null, '{{%menubuilder_items}}', ['groupId', 'handle'], false);
            $this->createIndex(null, '{{%menubuilder_items}}', ['elementId'], false);
            // Unique: a content element belongs to exactly one item, so two
            // items sharing one would mean two items sharing field values.
            $this->createIndex(null, '{{%menubuilder_items}}', ['contentId'], true);

            $this->addForeignKey(
                null,
                '{{%menubuilder_items}}',
                ['groupId'],
                '{{%menubuilder_groups}}',
                ['id'],
                'CASCADE',
                null
            );

            $this->addForeignKey(
                null,
                '{{%menubuilder_items}}',
                ['parentId'],
                '{{%menubuilder_items}}',
                ['id'],
                'CASCADE',
                null
            );

            // SET NULL, not CASCADE: an item whose content element is gone
            // is an item with empty fields, not an item that should vanish.
            // The opposite case — the parentId cascade above removing an
            // item row without passing through PHP, stranding its content
            // element — is swept by MenuBuilder's garbage collector.
            $this->addCraftForeignKey('{{%menubuilder_items}}', 'contentId', Table::ELEMENTS);
        }

        return true;
    }

    /**
     * `menubuilder_items` owns both foreign keys (its own `groupId` and the
     * self-referencing `parentId`), so dropping it first leaves nothing
     * pointing at `menubuilder_groups` — the previous
     * `MigrationHelper::dropAllForeignKeysOnTable()` calls were both
     * redundant and deprecated (in Craft 4.0).
     *
     * Dropping these two tables is the whole uninstall: group configuration
     * is database-backed only (see MenuBuilderGroupService), so there is no
     * project-config path left behind for a reinstall to replay.
     */
    public function safeDown(): bool
    {
        // Craft owns the `elements` and `fieldlayouts` rows this plugin
        // points at, so uninstalling has to hand them back explicitly, and
        // in this order: dropping the two tables below removes the only
        // columns naming those rows, and the garbage collector would then
        // have nothing left to recognise them by.
        //
        // Both are guarded on the columns existing, so this also runs
        // cleanly against an install whose tables predate them.
        $this->deleteContentElements();
        $this->deleteFieldLayouts();

        $this->dropTableIfExists('{{%menubuilder_items}}');
        $this->dropTableIfExists('{{%menubuilder_groups}}');

        return true;
    }

    /**
     * Hard-deletes the content elements this plugin's items point at.
     *
     * Raw deletes on **this migration's own connection**, not through
     * `Elements::deleteElement()`: `$this->db` is not always Craft's default
     * connection (the install test drives this migration against a schema of
     * its own), and a service call would silently operate on the wrong one.
     *
     * A content element can own nested elements — a Matrix field's entries.
     * The `elements_owners` cascade removes the ownership rows here, and
     * Craft's own garbage collection then sweeps the nested elements whose
     * owner is gone (`Gc::deleteOrphanedNestedElements()`), which is exactly
     * the mechanism Craft relies on for its own nested content.
     */
    private function deleteContentElements(): void
    {
        if (
            !$this->db->tableExists('{{%menubuilder_items}}')
            || !$this->db->columnExists('{{%menubuilder_items}}', 'contentId')
            || !$this->db->tableExists(Table::ELEMENTS)
        ) {
            return;
        }

        $ids = (new Query())
            ->select(['contentId'])
            ->from('{{%menubuilder_items}}')
            ->where(['not', ['contentId' => null]])
            ->column($this->db);

        if ($ids !== []) {
            $this->delete(Table::ELEMENTS, ['id' => $ids]);
        }
    }

    /**
     * Deletes the field layouts this plugin's menus point at.
     *
     * A layout belongs to exactly one menu (duplicating a menu copies its
     * layout — see MenuBuilderGroupService), so there is never another owner
     * to consider. Left behind, they would be `fieldlayouts` rows nothing
     * can ever reach again.
     */
    private function deleteFieldLayouts(): void
    {
        if (
            !$this->db->tableExists('{{%menubuilder_groups}}')
            || !$this->db->columnExists('{{%menubuilder_groups}}', 'fieldLayoutId')
            || !$this->db->tableExists(Table::FIELDLAYOUTS)
        ) {
            return;
        }

        $ids = (new Query())
            ->select(['fieldLayoutId'])
            ->from('{{%menubuilder_groups}}')
            ->where(['not', ['fieldLayoutId' => null]])
            ->column($this->db);

        if ($ids !== []) {
            $this->delete(Table::FIELDLAYOUTS, ['id' => $ids]);
        }
    }

    /**
     * Adds a `SET NULL` foreign key from one of this plugin's tables to one
     * of Craft's, when that table is there to point at.
     *
     * See the call sites for why the guard exists. It is deliberately not a
     * silent no-op in production: Craft's tables are created by Craft's own
     * install migration long before any plugin's runs, so the only way to
     * reach the `return` is a schema this migration was pointed at
     * artificially.
     */
    private function addCraftForeignKey(string $table, string $column, string $referenceTable): void
    {
        if (!$this->db->tableExists($referenceTable)) {
            return;
        }

        $this->addForeignKey(null, $table, [$column], $referenceTable, ['id'], 'SET NULL', null);
    }
}

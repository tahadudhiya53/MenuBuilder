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
                // The menu's Craft field layout — the fields every item in it is offered.
                'fieldLayoutId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%menubuilder_groups}}', ['handle'], true);
            $this->createIndex(null, '{{%menubuilder_groups}}', ['fieldLayoutId'], false);

            // SET NULL, not CASCADE: deleting a field layout must never take the menu with it.
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
                // The `elements` row carrying this item's field layout content.
                'contentId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%menubuilder_items}}', ['groupId', 'parentId', 'sortOrder'], false);
            $this->createIndex(null, '{{%menubuilder_items}}', ['groupId', 'handle'], false);
            $this->createIndex(null, '{{%menubuilder_items}}', ['elementId'], false);
            // Unique: a content element belongs to exactly one item, so two items sharing one would
            // mean two items sharing field values.
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

            // SET NULL, not CASCADE: an item whose content element is gone is an item with empty
            // fields, not an item that should vanish.
            $this->addCraftForeignKey('{{%menubuilder_items}}', 'contentId', Table::ELEMENTS);
        }

        return true;
    }

    /**
     * `menubuilder_items` owns both foreign keys (its own `groupId` and the self-referencing
     * `parentId`), so dropping it first leaves nothing pointing at `menubuilder_groups` — the
     * previous `MigrationHelper::dropAllForeignKeysOnTable()` calls were both redundant and
     * deprecated (in Craft 4.0).
    */
    public function safeDown(): bool
    {
        // Craft owns the `elements` and `fieldlayouts` rows this plugin points at, so uninstalling
        // has to hand them back explicitly, and in this order: dropping the two tables below
        // removes the only columns naming those rows, and the garbage collector would then have
        // nothing left to recognise them by.
        $this->deleteContentElements();
        $this->deleteFieldLayouts();

        $this->dropTableIfExists('{{%menubuilder_items}}');
        $this->dropTableIfExists('{{%menubuilder_groups}}');

        return true;
    }

    /**
     * Hard-deletes the content elements this plugin's items point at.
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
     * Adds a `SET NULL` foreign key from one of this plugin's tables to one of Craft's, when that
     * table is there to point at.
    */
    private function addCraftForeignKey(string $table, string $column, string $referenceTable): void
    {
        if (!$this->db->tableExists($referenceTable)) {
            return;
        }

        $this->addForeignKey(null, $table, [$column], $referenceTable, ['id'], 'SET NULL', null);
    }
}

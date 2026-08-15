<?php

namespace Tahadudhiya\MenuBuilder\migrations;

use craft\db\Migration;
use craft\helpers\MigrationHelper;

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
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%menubuilder_groups}}', ['handle'], true);
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
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%menubuilder_items}}', ['groupId', 'parentId', 'sortOrder'], false);
            $this->createIndex(null, '{{%menubuilder_items}}', ['groupId', 'handle'], false);
            $this->createIndex(null, '{{%menubuilder_items}}', ['elementId'], false);

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
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%menubuilder_items}}')) {
            MigrationHelper::dropAllForeignKeysOnTable('{{%menubuilder_items}}');
            $this->dropTableIfExists('{{%menubuilder_items}}');
        }

        if ($this->db->tableExists('{{%menubuilder_groups}}')) {
            MigrationHelper::dropAllForeignKeysOnTable('{{%menubuilder_groups}}');
            $this->dropTableIfExists('{{%menubuilder_groups}}');
        }

        return true;
    }
}

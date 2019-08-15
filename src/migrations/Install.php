<?php

namespace internetztube\slugEqualsTitle\migrations;

use Craft;
use craft\db\Migration;

class Install extends Migration
{
    public $driver;

    public function safeUp()
    {
        $this->driver = Craft::$app->getConfig()->getDb()->driver;
        if ($this->createTables()) {
            $this->addForeignKeys();
            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
        }
        return true;
    }

    public function safeDown()
    {
        $this->driver = Craft::$app->getConfig()->getDb()->driver;
        $this->removeTables();
        return true;
    }

    protected function createTables()
    {
        $tablesCreated = false;
        $tableSchema = Craft::$app->db->schema->getTableSchema('{{%slugEqualsTitle_shouldRewrite}}');
        if ($tableSchema === null) {
            $tablesCreated = true;
            $this->createTable(
                '{{%slugEqualsTitle_shouldRewrite}}',
                [
                    'id' => $this->primaryKey(),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'dateUpdated' => $this->dateTime()->notNull(),
                    'uid' => $this->uid(),
                    'elementId' => $this->integer()->notNull(),
                    'enabled' => $this->boolean()->notNull(),
                ]
            );
        }
        return $tablesCreated;
    }

    protected function addForeignKeys()
    {
        $this->addForeignKey(
            $this->db->getForeignKeyName('{{%slugEqualsTitle_shouldRewrite}}', 'elementId'),
            '{{%slugEqualsTitle_shouldRewrite}}',
            'elementId',
            '{{%elements}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    protected function removeTables()
    {
        $this->dropTableIfExists('{{%slugEqualsTitle_shouldRewrite}}');
    }
}

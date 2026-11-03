<?php

declare(strict_types=1);

namespace internetztube\slugEqualsTitle\migrations;

use craft\db\Migration;
use craft\db\Table;
use internetztube\slugEqualsTitle\services\SlugService;

final class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(SlugService::TABLE)) {
            self::createElementsTable($this);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(SlugService::TABLE);
        return true;
    }

    /** The table of remembered choices (SlugService::TABLE); also built by the migration from the 3.0 table. */
    public static function createElementsTable(Migration $migration): void
    {
        $migration->createTable(SlugService::TABLE, [
            'id' => $migration->primaryKey(),
            'elementId' => $migration->integer()->notNull(),
            'enabled' => $migration->boolean()->notNull(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);
        $migration->createIndex(null, SlugService::TABLE, ['elementId'], true);
        $migration->addForeignKey(null, SlugService::TABLE, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
    }
}

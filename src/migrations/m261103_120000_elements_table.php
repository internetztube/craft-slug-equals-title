<?php

declare(strict_types=1);

namespace internetztube\slugEqualsTitle\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use internetztube\slugEqualsTitle\services\SlugService;

/**
 * Up to 3.0 the choices lived in `slugEqualsTitle_shouldRewrite`. A mixed-case table name breaks when a database moves
 * between a case-insensitive MySQL (macOS, Windows) and a case-sensitive one (Linux): the dump has it in lower case
 * and the queries don't find it (#21). The new table is lower-case, has one row per element (the old one had no unique
 * index: the latest row wins) and only rows of elements that still exist.
 */
final class m261103_120000_elements_table extends Migration
{
    public function safeUp(): bool
    {
        $this->db->getSchema()->refresh();
        if (!$this->db->tableExists(SlugService::TABLE)) {
            Install::createElementsTable($this);
        }
        foreach (['{{%slugEqualsTitle_shouldRewrite}}', '{{%slugequalstitle_shouldrewrite}}'] as $old) {
            if (!$this->db->tableExists($old)) {
                continue;
            }
            $choices = [];
            /** @var array<array{elementId: int|string, enabled: bool|int|string}> $rows */
            $rows = (new Query())
                ->select(['t.elementId', 't.enabled'])
                ->from(['t' => $old])
                ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[t.elementId]]')
                // a choice already in the new table (made since the update began) is newer than the old row
                ->andWhere(['not in', 't.elementId', (new Query())->select(['elementId'])->from(SlugService::TABLE)])
                ->orderBy(['t.id' => SORT_ASC])
                ->all($this->db);
            foreach ($rows as $row) {
                $choices[(int)$row['elementId']] = [(int)$row['elementId'], (bool)$row['enabled']];
            }
            foreach (array_chunk(array_values($choices), 1000) as $chunk) {
                Db::batchInsert(SlugService::TABLE, ['elementId', 'enabled'], $chunk, $this->db);
            }
            $this->dropTable($old);
        }
        return true;
    }

    public function safeDown(): bool
    {
        echo "m261103_120000_elements_table cannot be reverted.\n";
        return false;
    }
}

<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/** Adds only the generated calendar tables; excludes unrelated upstream baseline schema drift. */
final class Version20261027100000 extends AbstractMigration
{
    /** Describes the additive, owner-scoped calendar synchronization migration. */
    public function getDescription(): string
    {
        return 'Add encrypted calendar connections and durable event reconciliation links';
    }

    /** Creates calendar connection/link tables in $schema; native timesheets and imported history remain untouched. */
    public function up(Schema $schema): void
    {
        $connection = $schema->createTable('shift_calendar_connection');
        $connection->addColumn('id', 'integer', ['autoincrement' => true]);
        $connection->addColumn('owner_id', 'integer');
        $connection->addColumn('credentials', 'text', ['notnull' => false]);
        $connection->addColumn('settings', 'json');
        $connection->addColumn('enabled', 'boolean');
        $connection->addColumn('last_success', 'datetime_immutable', ['notnull' => false]);
        $connection->addColumn('error', 'text', ['notnull' => false]);
        $connection->setPrimaryKey(['id']);
        $connection->addUniqueIndex(['owner_id'], 'UNIQ_DFD45E587E3C61F9');
        $connection->addForeignKeyConstraint('kimai2_users', ['owner_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_DFD45E587E3C61F9');
        $link = $schema->createTable('shift_calendar_link');
        $link->addColumn('id', 'integer', ['autoincrement' => true]);
        $link->addColumn('connection_id', 'integer');
        $link->addColumn('local_key', 'string', ['length' => 80, 'notnull' => false]);
        $link->addColumn('state', 'string', ['length' => 30]);
        foreach (['baseline', 'remote', 'pending'] as $column) {
            $link->addColumn($column, 'json', ['notnull' => $column !== 'pending']);
        }
        $link->addColumn('problem', 'text', ['notnull' => false]);
        $link->addColumn('attempts', 'integer');
        $link->addColumn('retry_at', 'datetime_immutable', ['notnull' => false]);
        $link->addColumn('calendar_id', 'string', ['length' => 255]);
        $link->addColumn('event_id', 'string', ['length' => 255]);
        $link->setPrimaryKey(['id']);
        $link->addIndex(['connection_id'], 'IDX_1F78239FDD03F01');
        $link->addUniqueIndex(['connection_id', 'calendar_id', 'event_id'], 'UNIQ_1F78239FDD03F01A40A2C871F7E88B');
        $link->addUniqueIndex(['connection_id', 'local_key'], 'UNIQ_1F78239FDD03F01961EA98D');
        $link->addForeignKeyConstraint('shift_calendar_connection', ['connection_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_1F78239FDD03F01');
    }

    /** Drops only new sync state from $schema; perform a private backup first to preserve remote event links. */
    public function down(Schema $schema): void
    {
        $schema->dropTable('shift_calendar_link');
        $schema->dropTable('shift_calendar_connection');
    }
}

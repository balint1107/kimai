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

/** Adds private import provenance without changing upstream Kimai tables. */
final class Version20261026130252 extends AbstractMigration
{
    /** Returns the developer-facing migration description. */
    public function getDescription(): string
    {
        return 'Add shift import batches and source items';
    }

    /** Creates provenance tables in $schema, keeping deleted shifts as nullable source links. */
    public function up(Schema $schema): void
    {
        $batch = $schema->createTable('shift_import_batch');
        $batch->addColumn('id', 'integer', ['autoincrement' => true]);
        $batch->addColumn('owner_id', 'integer');
        $batch->addColumn('checksum', 'string', ['length' => 64]);
        $batch->addColumn('filename', 'string', ['length' => 255]);
        $batch->addColumn('created_at', 'datetime_immutable');
        $batch->addColumn('imported', 'boolean');
        $batch->setPrimaryKey(['id']);
        $batch->addUniqueIndex(['owner_id', 'checksum']);
        $batch->addForeignKeyConstraint('kimai2_users', ['owner_id'], ['id'], ['onDelete' => 'CASCADE']);
        $item = $schema->createTable('shift_import_item');
        $item->addColumn('id', 'integer', ['autoincrement' => true]);
        $item->addColumn('batch_id', 'integer');
        $item->addColumn('timesheet_id', 'integer', ['notnull' => false]);
        $item->addColumn('coordinate', 'string', ['length' => 100]);
        $item->addColumn('payload', 'json');
        $item->addColumn('resolved', 'boolean');
        $item->setPrimaryKey(['id']);
        $item->addUniqueIndex(['batch_id', 'coordinate']);
        $item->addForeignKeyConstraint('shift_import_batch', ['batch_id'], ['id'], ['onDelete' => 'CASCADE']);
        $item->addForeignKeyConstraint('kimai2_timesheet', ['timesheet_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    /** Drops only the import provenance tables from $schema; native shifts remain untouched. */
    public function down(Schema $schema): void
    {
        $schema->dropTable('shift_import_item');
        $schema->dropTable('shift_import_batch');
    }
}

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

/** Adds private absences without modifying native timesheets or earnings. */
final class Version20261026170000 extends AbstractMigration
{
    /** Returns the migration's purpose for deployment logs. */
    public function getDescription(): string
    {
        return 'Add owner-scoped calendar time off';
    }

    /** Creates $schema's UTC absence table, cascading only when its owner is deleted. */
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('shift_time_off');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('owner_id', 'integer');
        $table->addColumn('start_at', 'datetime_immutable');
        $table->addColumn('end_at', 'datetime_immutable');
        $table->addColumn('all_day', 'boolean');
        $table->addColumn('note', 'string', ['length' => 200]);
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint('kimai2_users', ['owner_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    /** Removes only $schema's calendar absences when rolling back this migration. */
    public function down(Schema $schema): void
    {
        $schema->dropTable('shift_time_off');
    }
}

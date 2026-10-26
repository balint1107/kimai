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

/** Extends calendar-only entries; existing records remain personal holidays. */
final class Version20261026180000 extends AbstractMigration
{
    /** Returns the migration purpose for deployment logs. */
    public function getDescription(): string
    {
        return 'Add general event type and title to calendar entries';
    }

    /** Adds $schema's type/title columns with backwards-compatible defaults for existing holidays. */
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('shift_time_off');
        $table->addColumn('kind', 'string', ['length' => 20, 'default' => 'time_off']);
        $table->addColumn('title', 'string', ['length' => 150, 'default' => '']);
    }

    /** Removes $schema's general-event fields; restore a backup to retain general-event titles on rollback. */
    public function down(Schema $schema): void
    {
        $table = $schema->getTable('shift_time_off');
        $table->dropColumn('kind');
        $table->dropColumn('title');
    }
}

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

/** Allows personal calendar colors while preserving the existing type defaults. */
final class Version20261026181000 extends AbstractMigration
{
    /** Returns the migration purpose for deployment logs. */
    public function getDescription(): string
    {
        return 'Add optional saved color to calendar entries';
    }

    /** Adds a nullable color to $schema; null keeps the existing holiday/general-event palette. */
    public function up(Schema $schema): void
    {
        $schema->getTable('shift_time_off')->addColumn('color', 'string', ['length' => 7, 'notnull' => false]);
    }

    /** Removes only the color from $schema; calendar titles, dates and notes remain. */
    public function down(Schema $schema): void
    {
        $schema->getTable('shift_time_off')->dropColumn('color');
    }
}

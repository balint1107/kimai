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
use Doctrine\DBAL\Schema\SchemaConfig;

/** Adds private ownership without changing native customer, project or timesheet identities. */
final class Version20261027150006 extends AbstractMigration
{
    /** Describes the additive private-account migration in deployment output. */
    public function getDescription(): string
    {
        return 'Private workplace ownership, exclusive Google calendars and account binding';
    }

    /** Validates legacy ownership before DDL, then creates $schema additions and queues deterministic backfill. */
    public function up(Schema $schema): void
    {
        $places = $this->connection->fetchAllAssociative("SELECT c.id, c.name FROM kimai2_customers c JOIN kimai2_customers_meta m ON m.customer_id = c.id AND m.name = 'shift_workplace' AND m.value = '1'");
        $owners = $this->connection->fetchFirstColumn("SELECT DISTINCT t.user FROM kimai2_timesheet t JOIN kimai2_projects p ON p.id = t.project_id JOIN kimai2_customers_meta m ON m.customer_id = p.customer_id AND m.name = 'shift_workplace' AND m.value = '1'");
        if ($owners === []) {
            $owners = $this->connection->fetchFirstColumn('SELECT id FROM kimai2_users');
        }
        $fallback = \count($owners) === 1 ? (int) $owners[0] : null;
        $assignments = [];
        $names = [];
        foreach ($places as $place) {
            $users = $this->connection->fetchFirstColumn('SELECT DISTINCT t.user FROM kimai2_timesheet t JOIN kimai2_projects p ON p.id = t.project_id WHERE p.customer_id = ?', [$place['id']]);
            $this->abortIf(\count($users) > 1 || ($users === [] && $fallback === null), 'Ambiguous legacy workplace ownership; split/assign customers before upgrading');
            $owner = $users === [] ? $fallback : (int) $users[0];
            $key = hash('sha256', mb_strtolower(\Normalizer::normalize(trim($place['name'])) ?: trim($place['name'])));
            $this->abortIf(isset($names[$owner . ':' . $key]), 'Duplicate normalized workplace name for one owner; resolve before upgrading');
            $names[$owner . ':' . $key] = true;
            $assignments[] = [$owner, $place['id'], $key];
        }
        $claims = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, settings FROM shift_calendar_connection') as $connection) {
            $settings = json_decode($connection['settings'], true, 512, JSON_THROW_ON_ERROR);
            $calendars = array_merge($settings['calendars'] ?? [], array_values($settings['setup_calendars'] ?? []), $this->connection->fetchFirstColumn('SELECT DISTINCT calendar_id FROM shift_calendar_link WHERE connection_id = ?', [$connection['id']]));
            foreach (array_unique($calendars) as $calendar) {
                $this->abortIf($calendar === 'primary' || $calendar === '', 'Replace relative calendar aliases with canonical IDs before upgrading');
                $key = hash('sha256', $calendar);
                $this->abortIf(isset($claims[$key]) && $claims[$key][0] !== $connection['id'], 'A Google calendar belongs to multiple accounts; resolve before upgrading');
                $claims[$key] = [$connection['id'], $calendar];
            }
        }
        // Generate only the new tables, excluding unrelated upstream schema drift from the generated diff.
        $options = new SchemaConfig();
        $options->setDefaultTableOptions(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        $new = new Schema([], [], $options);
        $table = $new->createTable('shift_workplace');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('owner_id', 'integer');
        $table->addColumn('customer_id', 'integer');
        $table->addColumn('name_key', 'string', ['length' => 64]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['customer_id']);
        $table->addUniqueIndex(['owner_id', 'name_key']);
        $table->addForeignKeyConstraint('kimai2_users', ['owner_id'], ['id'], ['onDelete' => 'RESTRICT']);
        $table->addForeignKeyConstraint('kimai2_customers', ['customer_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table = $new->createTable('shift_calendar_claim');
        $table->addColumn('id', 'string', ['length' => 64]);
        $table->addColumn('connection_id', 'integer');
        $table->addColumn('calendar_id', 'text');
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint('shift_calendar_connection', ['connection_id'], ['id'], ['onDelete' => 'CASCADE']);
        foreach ($new->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('ALTER TABLE shift_calendar_connection ADD account_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DFD45E589B6B5FBA ON shift_calendar_connection (account_id)');
        foreach ($assignments as $assignment) {
            $this->addSql('INSERT INTO shift_workplace (owner_id, customer_id, name_key) VALUES (?, ?, ?)', $assignment);
        }
        foreach ($claims as $key => [$connection, $calendar]) {
            $this->addSql('INSERT INTO shift_calendar_claim (id, connection_id, calendar_id) VALUES (?, ?, ?)', [$key, $connection, $calendar]);
        }
    }

    /** Removes only ownership additions from $schema; native records survive, but use a matching backup for production rollback. */
    public function down(Schema $schema): void
    {
        $schema->dropTable('shift_calendar_claim');
        $schema->dropTable('shift_workplace');
        $table = $schema->getTable('shift_calendar_connection');
        $table->dropIndex('UNIQ_DFD45E589B6B5FBA');
        $table->dropColumn('account_id');
    }
}

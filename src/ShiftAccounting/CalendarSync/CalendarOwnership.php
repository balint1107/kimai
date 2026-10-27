<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\ShiftAccounting\CalendarSync;

use App\Entity\ShiftCalendarClaim;
use App\Entity\ShiftCalendarConnection;
use Doctrine\ORM\EntityManagerInterface;

/** Serializes exclusive Google calendar reservations across all application accounts. */
final class CalendarOwnership
{
    /** Uses $em for durable claims and a database-wide advisory lock. */
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** Claims canonical $calendars for persisted $connection; throws before writes if another account owns any ID.
     * @param list<string> $calendars Google calendar IDs, never the account-relative alias "primary"
     */
    public function claim(ShiftCalendarConnection $connection, array $calendars): void
    {
        if ($connection->id === null || \in_array('primary', $calendars, true)) {
            throw new \RuntimeException('Select explicit calendars on a saved connection');
        }
        $db = $this->em->getConnection();
        if (!\in_array($db->fetchOne('SELECT GET_LOCK(?, 0)', ['kimai-calendar-ownership']), [1, '1'], true)) {
            throw new \RuntimeException('Calendar ownership is busy; retry later');
        }
        try {
            $missing = [];
            foreach (array_unique($calendars) as $calendar) {
                if ($calendar === '') {
                    throw new \RuntimeException('Select a calendar');
                }
                $id = hash('sha256', $calendar);
                $claim = $this->em->find(ShiftCalendarClaim::class, $id);
                if ($claim !== null && $claim->connection->id !== $connection->id) {
                    throw new \RuntimeException('Calendar is reserved by another application account');
                }
                if ($claim === null) {
                    $missing[] = new ShiftCalendarClaim($id, $connection, $calendar);
                }
            }
            foreach ($missing as $claim) {
                $this->em->persist($claim);
            }
            $this->em->flush();
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', ['kimai-calendar-ownership']);
        }
    }
}

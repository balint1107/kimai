<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Entity;

use App\Entity\ShiftCalendarClaim;
use App\Entity\ShiftCalendarConnection;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/** Verifies durable calendar reservation identity. */
class ShiftCalendarClaimTest extends TestCase
{
    /** Reservation retains canonical $calendar identity and its owning connection. */
    public function testIdentity(): void
    {
        $calendar = 'calendar@example.test';
        $connection = new ShiftCalendarConnection(new User());
        $claim = new ShiftCalendarClaim(hash('sha256', $calendar), $connection, $calendar);
        self::assertSame($connection, $claim->connection);
        self::assertSame($calendar, $claim->calendarId);
        self::assertSame(hash('sha256', $calendar), $claim->id);
    }
}

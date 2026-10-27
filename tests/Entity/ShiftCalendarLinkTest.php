<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Entity;

use App\Entity\ShiftCalendarConnection;
use App\Entity\ShiftCalendarLink;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/** Verifies new event links begin in review without an outbound operation or local identity. */
class ShiftCalendarLinkTest extends TestCase
{
    /** Preserves exact provider identity under the owning connection and requires explicit reconciliation. */
    public function testReviewDefaults(): void
    {
        $connection = new ShiftCalendarConnection(new User());
        $link = new ShiftCalendarLink($connection, 'calendar', 'event');
        self::assertSame($connection, $link->connection);
        self::assertSame('calendar', $link->calendarId);
        self::assertSame('event', $link->eventId);
        self::assertSame('review', $link->state);
        self::assertNull($link->pending);
        self::assertNull($link->localKey);
    }
}

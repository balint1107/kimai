<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Entity;

use App\Entity\ShiftTimeOff;
use App\Entity\User;
use App\ShiftAccounting\TimeOffService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** Checks that a persisted-shaped absence keeps an independent ID and calendar event kind. */
class ShiftTimeOffTest extends TestCase
{
    /** Ensures absence event IDs cannot collide with native numeric timesheet IDs. */
    public function testCalendarIdentity(): void
    {
        $absence = new ShiftTimeOff(new User(), new \DateTimeImmutable('2026-01-01T00:00:00Z'), new \DateTimeImmutable('2026-01-02T00:00:00Z'), true);
        $absence->id = 123;
        $event = (new TimeOffService($this->createMock(EntityManagerInterface::class)))->record($absence);
        self::assertSame('timeoff-123', $event['id']);
        self::assertSame('time_off', $event['kind']);
        self::assertSame(123, $event['absence_id']);
    }
}

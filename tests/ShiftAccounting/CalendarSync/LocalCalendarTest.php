<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting\CalendarSync;

use App\Entity\User;
use App\ShiftAccounting\CalendarSync\LocalCalendar;
use App\ShiftAccounting\ShiftService;
use App\ShiftAccounting\TimeOffService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** Validates the local adapter rejects invalid nonfinancial kinds before persistence; lifecycle is covered by CalendarSyncTest. */
class LocalCalendarTest extends TestCase
{
    /** Unknown event types cannot bypass native calendar validation through synchronization. */
    public function testInvalidTypeDoesNotPersist(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $shifts = (new \ReflectionClass(ShiftService::class))->newInstanceWithoutConstructor();
        $adapter = new LocalCalendar($em, $shifts, new TimeOffService($em));
        $this->expectException(\InvalidArgumentException::class);
        $adapter->save(new User(), ['kind' => 'unknown', 'begin' => '1793584800', 'end' => '1793588400', 'all_day' => false, 'note' => '', 'title' => 'Unknown'], null);
    }
}

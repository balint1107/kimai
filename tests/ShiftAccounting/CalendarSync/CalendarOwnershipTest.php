<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting\CalendarSync;

use App\Entity\ShiftCalendarClaim;
use App\Entity\ShiftCalendarConnection;
use App\Entity\User;
use App\ShiftAccounting\CalendarSync\CalendarOwnership;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** Tests exclusive reservations before any foreign-calendar event can be read or written. */
class CalendarOwnershipTest extends TestCase
{
    /** Foreign reservation aborts the whole selection without persisting any partial claims. */
    public function testForeignCalendar(): void
    {
        $owner = new ShiftCalendarConnection(new User());
        $owner->id = 1;
        $other = new ShiftCalendarConnection(new User());
        $other->id = 2;
        $em = $this->createMock(EntityManagerInterface::class);
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))->method('fetchOne')->willReturn(1);
        $em->method('getConnection')->willReturn($db);
        $em->method('find')->willReturnCallback(static fn ($class, $id) => $id === hash('sha256', 'taken') ? new ShiftCalendarClaim($id, $other, 'taken') : null);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $this->expectException(\RuntimeException::class);
        (new CalendarOwnership($em))->claim($owner, ['free', 'taken']);
    }

    /** Own reservations are reused while a missing canonical calendar is durably reserved. */
    public function testOwnCalendar(): void
    {
        $owner = new ShiftCalendarConnection(new User());
        $owner->id = 1;
        $em = $this->createMock(EntityManagerInterface::class);
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(1);
        $em->method('getConnection')->willReturn($db);
        $em->method('find')->willReturnCallback(static fn ($class, $id) => $id === hash('sha256', 'existing') ? new ShiftCalendarClaim($id, $owner, 'existing') : null);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(ShiftCalendarClaim::class));
        $em->expects(self::once())->method('flush');
        (new CalendarOwnership($em))->claim($owner, ['existing', 'new', 'new']);
    }
}

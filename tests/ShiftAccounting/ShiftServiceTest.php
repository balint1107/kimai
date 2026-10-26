<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting;

use App\Entity\Timesheet;
use App\Entity\TimesheetMeta;
use App\ShiftAccounting\ShiftService;
use PHPUnit\Framework\TestCase;

/** Verifies Budapest parsing and time-derived accounting state without a clock job. */
class ShiftServiceTest extends TestCase
{
    /** Exercises pure methods without constructing persistence collaborators. */
    private function service(): ShiftService
    {
        return (new \ReflectionClass(ShiftService::class))->newInstanceWithoutConstructor();
    }

    /** Rejects nonexistent spring DST times instead of silently moving a scheduled shift. */
    public function testRejectsNonexistentTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->date('2026-03-29T02:30');
    }

    /** Rejects impossible calendar dates even when an explicit offset is supplied. */
    public function testRejectsNormalizedDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->date('2026-02-30T08:00+01:00');
    }

    /** Checks elapsed hours across DST and explicit offsets during the repeated autumn hour. */
    public function testBudapestDstAndOvernight(): void
    {
        $service = $this->service();
        self::assertSame(3600, $service->date('2026-03-29T03:30')->getTimestamp() - $service->date('2026-03-29T01:30')->getTimestamp());
        self::assertSame(3600, $service->date('2026-10-25T02:30+01:00')->getTimestamp() - $service->date('2026-10-25T02:30+02:00')->getTimestamp());
        self::assertSame(7200, $service->date('2026-01-01T01:00')->getTimestamp() - $service->date('2025-12-31T23:00')->getTimestamp());
    }

    /** Moves a shift from planned through in-progress to completed, with cancellation taking precedence. */
    public function testStateTransitions(): void
    {
        $shift = (new Timesheet())->setBegin(new \DateTime('2026-10-26T08:00+01:00'))->setEnd(new \DateTime('2026-10-26T12:00+01:00'));
        $service = $this->service();
        self::assertSame('planned', $service->state($shift, new \DateTimeImmutable('2026-10-26T07:59+01:00')));
        self::assertSame('in_progress', $service->state($shift, new \DateTimeImmutable('2026-10-26T10:00+01:00')));
        self::assertSame('completed', $service->state($shift, new \DateTimeImmutable('2026-10-26T12:00+01:00')));
        $shift->setMetaField((new TimesheetMeta())->setName('shift_cancelled')->setValue('1'));
        self::assertSame('cancelled', $service->state($shift));
        self::assertTrue($service->record($shift)['cancelled']);
    }
}

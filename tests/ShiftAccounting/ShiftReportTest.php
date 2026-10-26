<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\TimesheetMeta;
use App\ShiftAccounting\ShiftReport;
use App\ShiftAccounting\ShiftService;
use PHPUnit\Framework\TestCase;

/** Checks summary/export totals, cancellation and spreadsheet text safety. */
class ShiftReportTest extends TestCase
{
    /** Creates a persisted-calculation-shaped shift with $rate HUF/hour and Budapest-local $begin/$end. */
    private function shift(string $begin, string $end, float $rate): Timesheet
    {
        $customer = new Customer('Test workplace');
        $customer->setName('=Test workplace');
        $shift = (new Timesheet())->setBegin(new \DateTime($begin, new \DateTimeZone('Europe/Budapest')))->setEnd(new \DateTime($end, new \DateTimeZone('Europe/Budapest')));
        $shift->setProject((new Project())->setCustomer($customer)->setName('Shifts'));
        $shift->setActivity((new Activity())->setName('EFO'));
        self::assertNotNull($shift->getEnd());
        self::assertNotNull($shift->getBegin());
        $seconds = $shift->getEnd()->getTimestamp() - $shift->getBegin()->getTimestamp();
        $shift->setDuration($seconds);
        $shift->setHourlyRate($rate);
        $shift->setRate($seconds / 3600 * $rate);

        return $shift;
    }

    /** Counts each shift once under its start month/year; cancelled rows and zero rates behave independently. */
    public function testReportsAndExport(): void
    {
        $service = (new \ReflectionClass(ShiftService::class))->newInstanceWithoutConstructor();
        $report = new ShiftReport($service);
        $shift = $this->shift('2023-12-31T23:00', '2024-01-01T00:30', 7500);
        $zero = $this->shift('2023-12-01T08:00', '2023-12-01T09:00', 0);
        $cancelled = $this->shift('2023-12-02T08:00', '2023-12-02T09:00', 9000);
        $cancelled->setMetaField((new TimesheetMeta())->setName('shift_cancelled')->setValue('1'));
        $groups = $report->build([$shift, $zero, $cancelled]);
        self::assertSame('2023-12', $groups['months'][0]['period']);
        self::assertSame(2, $groups['months'][0]['count']);
        self::assertSame(9000, $groups['months'][0]['seconds']);
        self::assertSame(11250.0, $groups['months'][0]['amount']);
        $book = $report->workbook([$shift, $zero, $cancelled]);
        self::assertCount(4, $book->getAllSheets());
        $details = $book->getSheetByName('2023.12.');
        $summary = $book->getSheetByName('Összegzés');
        self::assertNotNull($details);
        self::assertNotNull($summary);
        self::assertSame('s', $details->getCell('B2')->getDataType());
        self::assertSame(11250.0, $summary->getCell('F2')->getValue());
        self::assertSame([], $report->build([])['months']);
        $book->disconnectWorksheets();
    }
}

<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting;

use App\ShiftAccounting\WorkbookImport;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/** Uses synthetic XLSX files to validate source parsing without including private workbook data. */
class WorkbookImportTest extends TestCase
{
    /** Reads shifted block headers and retains missing categories/workplaces/times without trusting formulas. */
    public function testHeaderDetectionAndReviewFlags(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('2024.04.');
        $sheet->fromArray([['HELYSZÍN', 'DÁTUM', 'KEZDETE', 'VÉGE', 'ÓRABÉR', 'ÖSSZEG', 'EFO/SZÁMLA']]);
        $day = Date::PHPToExcel(new \DateTime('2023-04-01'));
        $sheet->fromArray([
            ['Test', $day, 8 / 24, 12.5 / 24, 7500, '=1', null],
            [null, $day, 8 / 24, 12 / 24, 0, '=999', 'efo'],
            ['Test', $day, null, null, 7500, null, 'szla'],
            [null, $day, null, null, 7500, null, null],
        ], null, 'A2', true);
        $sheet->fromArray([['HELYSZÍN', 'KEZDETE', 'VÉGE', 'ÓRABÉR', 'ÖSSZEG', 'EOF/SZÁMLA']], null, 'O1');
        $sheet->fromArray([['Other', 9 / 24, 10 / 24, 8000, '=1', 'szla']], null, 'O2');
        $path = tempnam(sys_get_temp_dir(), 'shift-test-');
        try {
            (new Xlsx($book))->save($path);
            $parser = (new \ReflectionClass(WorkbookImport::class))->newInstanceWithoutConstructor();
            $rows = $parser->parse($path);
            self::assertCount(4, $rows);
            self::assertFalse($rows[0]['usable']);
            self::assertSame(33750.0, $rows[0]['source_amount']);
            self::assertContains('date_mismatch', $rows[0]['flags']);
            self::assertContains('missing_category', $rows[0]['flags']);
            self::assertSame('Unknown', $rows[1]['workplace']);
            self::assertFalse($rows[1]['usable']);
            self::assertNotEmpty($rows[1]['begin']);
            self::assertSame(0.0, $rows[1]['source_amount']);
            self::assertFalse($rows[2]['usable']);
            self::assertSame('Számla', $rows[3]['category']);
            self::assertTrue($rows[3]['usable']);
            self::assertSame('2024.04.:2:15', $rows[3]['coordinate']);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    /** Refuses a non-ZIP file before invoking the XLSX parser. */
    public function testRejectsInvalidWorkbook(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shift-invalid-');
        try {
            file_put_contents($path, 'invalid');
            $this->expectException(\InvalidArgumentException::class);
            (new \ReflectionClass(WorkbookImport::class))->newInstanceWithoutConstructor()->parse($path);
        } finally {
            unlink($path);
        }
    }
}

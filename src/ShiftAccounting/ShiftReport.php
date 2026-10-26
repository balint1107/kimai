<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\ShiftAccounting;

use App\Entity\Timesheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Builds every accounting view/export from the same native shift records.
 * @phpstan-type Aggregate array{period:string,category:string,state:string,count:int,seconds:int,amount:float}
 */
final class ShiftReport
{
    /** Injects $shifts for consistent timestamp state and cancellation handling. */
    public function __construct(private ShiftService $shifts)
    {
    }

    /** Aggregates $records by month, year and workplace/category/state.
     * @param list<Timesheet> $records
     * @return array<string, list<Aggregate>>
     */
    public function build(array $records): array
    {
        $groups = ['months' => [], 'years' => [], 'workplaces' => [], 'workplace_years' => []];
        foreach ($records as $shift) {
            $date = $shift->getBegin();
            if ($date === null || $this->shifts->state($shift) === 'cancelled') {
                continue;
            }
            $date->setTimezone(new \DateTimeZone('Europe/Budapest'));
            $state = $this->shifts->state($shift) === 'completed' ? 'completed' : 'planned';
            $workplace = $shift->getProject()?->getCustomer()?->getName() ?? ShiftService::UNKNOWN;
            $category = $shift->getActivity()?->getName() ?? ShiftService::UNKNOWN;
            foreach (['months' => $date->format('Y-m'), 'years' => $date->format('Y'), 'workplaces' => $date->format('Y-m') . ' · ' . $workplace, 'workplace_years' => $date->format('Y') . ' · ' . $workplace] as $type => $period) {
                $key = $period . '|' . $category . '|' . $state;
                $groups[$type][$key] ??= ['period' => $period, 'category' => $category, 'state' => $state, 'count' => 0, 'seconds' => 0, 'amount' => 0.0];
                $groups[$type][$key]['count']++;
                $groups[$type][$key]['seconds'] += $shift->getDuration() ?? 0;
                $groups[$type][$key]['amount'] += $shift->getRate();
            }
        }
        $result = [];
        foreach ($groups as $type => $group) {
            ksort($group);
            $result[$type] = array_values($group);
        }

        return $result;
    }

    /** Creates an XLSX-ready workbook from $records, including all three summaries and monthly detail.
     * @param list<Timesheet> $records
     */
    public function workbook(array $records): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        $groups = $this->build($records);
        $groups['months'] = array_merge($groups['months'], $groups['years']);
        foreach (['months' => 'Összegzés', 'workplaces' => 'Munkahely Idő', 'workplace_years' => 'Munkahely Év'] as $type => $title) {
            $sheet = $book->createSheet()->setTitle($title);
            $rows = [['Időszak / munkahely', 'EFO/Számla', 'Állapot', 'Alkalom', 'Óra', 'Összeg (HUF)']];
            foreach ($groups[$type] as $row) {
                $rows[] = [$row['period'], $row['category'], $row['state'] === 'completed' ? 'Teljesített' : 'Tervezett', $row['count'], $row['seconds'] / 3600, $row['amount']];
            }
            $this->writeRows($sheet, $rows);
        }
        $months = [];
        foreach ($records as $shift) {
            $month = $shift->getBegin()?->format('Y.m.') ?? 'Unknown';
            $months[$month] ??= [['Dátum', 'Helyszín', 'Kezdete', 'Vége', 'Órabér', 'Összeg', 'EFO/Számla', 'Óra', 'Állapot', 'Megjegyzés']];
            $months[$month][] = [
                $shift->getBegin()?->format('Y-m-d'), $shift->getProject()?->getCustomer()?->getName(),
                $shift->getBegin()?->format('Y-m-d H:i'), $shift->getEnd()?->format('Y-m-d H:i'),
                $shift->getHourlyRate(), $shift->getRate(), $shift->getActivity()?->getName(),
                ($shift->getDuration() ?? 0) / 3600, $this->shifts->state($shift), $shift->getDescription(),
            ];
        }
        foreach ($months as $month => $rows) {
            $this->writeRows($book->createSheet()->setTitle($month), $rows);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** Writes $rows without interpreting user text as formulas; freezes and formats $sheet.
     * @param list<list<mixed>> $rows
     */
    private function writeRows(Worksheet $sheet, array $rows): void
    {
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $cell = $sheet->getCell([$c + 1, $r + 1]);
                if (\is_string($value)) {
                    $cell->setValueExplicit($value, DataType::TYPE_STRING);
                } else {
                    $cell->setValue($value);
                }
            }
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
    }
}

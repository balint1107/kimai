<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\ShiftAccounting;

use App\Entity\ShiftImportBatch;
use App\Entity\ShiftImportItem;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Reads workbook source cells, preserving incomplete records and independently calculated controls.
 * @phpstan-import-type SourceRow from ShiftImportItem
 */
final class WorkbookImport
{
    /** Injects $em for import provenance and $shifts for native Kimai shift creation. */
    public function __construct(private EntityManagerInterface $em, private ShiftService $shifts)
    {
    }

    /** Reads $path as XLSX with size limits; returns normalized candidates, never evaluating formulas.
     * @return list<SourceRow>
     */
    public function parse(string $path): array
    {
        $zip = new \ZipArchive();
        if (filesize($path) > 20 * 1024 * 1024 || $zip->open($path) !== true) {
            throw new \InvalidArgumentException('shift.invalid_workbook');
        }
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new \InvalidArgumentException('shift.invalid_workbook');
            }
            $size += $stat['size'];
        }
        $zip->close();
        if ($size > 100 * 1024 * 1024) {
            throw new \InvalidArgumentException('shift.invalid_workbook');
        }
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(array_values(array_filter($reader->listWorksheetNames($path), static fn (string $name): bool => preg_match('/^\d{4}[.]\d{2}[.]$/D', $name) === 1)));
        $book = $reader->load($path);
        $result = [];
        foreach ($book->getAllSheets() as $sheet) {
            if (preg_match('/^\d{4}[.]\d{2}[.]$/D', $sheet->getTitle()) !== 1) {
                continue;
            }
            $headers = [];
            $blocks = [];
            $dateColumn = null;
            $maxColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            for ($c = 1; $c <= $maxColumn; $c++) {
                $header = mb_strtoupper(trim($this->text($sheet->getCell([$c, 1])->getValue())));
                $headers[$c] = $header;
                if ($header === 'HELYSZÍN') {
                    $blocks[] = $c;
                }
                if ($header === 'DÁTUM') {
                    $dateColumn = $c;
                }
            }
            if ($dateColumn === null) {
                continue;
            }
            foreach ($blocks as $blockIndex => $column) {
                $fields = ['workplace' => $column];
                $limit = $blocks[$blockIndex + 1] ?? ($maxColumn + 1);
                for ($c = $column + 1; $c < $limit; $c++) {
                    $key = match ($headers[$c]) {
                        'KEZDETE' => 'begin', 'VÉGE' => 'end', 'ÓRABÉR' => 'rate',
                        'EFO/SZÁMLA', 'EOF/SZÁMLA' => 'category', default => null,
                    };
                    if ($key !== null) {
                        $fields[$key] = $c;
                    }
                }
                if (\count($fields) !== 5) {
                    throw new \InvalidArgumentException('shift.invalid_workbook');
                }
                for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
                    $rawDate = $sheet->getCell([$dateColumn, $r])->getValue();
                    $raw = [];
                    foreach ($fields as $key => $c) {
                        $value = $sheet->getCell([$c, $r])->getValue();
                        $raw[$key] = \is_string($value) && str_starts_with($value, '=') ? null : $value;
                    }
                    if (empty($raw['workplace']) && empty($raw['begin']) && empty($raw['end']) && empty($raw['category'])) {
                        continue;
                    }
                    $flags = [];
                    $date = is_numeric($rawDate) ? Date::excelToDateTimeObject((float) $rawDate)->format('Y-m-d') : null;
                    if ($date === null) {
                        $flags[] = 'missing_date';
                    } elseif (substr($date, 0, 7) !== str_replace('.', '-', substr($sheet->getTitle(), 0, 7))) {
                        $flags[] = 'date_mismatch';
                    }
                    $category = match (mb_strtolower(trim($this->text($raw['category'])))) {
                        'efo' => 'EFO', 'szla', 'számla' => 'Számla', default => ShiftService::UNKNOWN,
                    };
                    $workplace = trim($this->text($raw['workplace']));
                    if ($workplace === '' || \in_array(mb_strtolower($workplace), ['unknown', 'ismeretlen'], true)) {
                        $flags[] = 'missing_workplace';
                    }
                    if ($category === ShiftService::UNKNOWN) {
                        $flags[] = 'missing_category';
                    }
                    $begin = $this->time($raw['begin']);
                    $end = $this->time($raw['end']);
                    $rate = is_numeric($raw['rate']) ? (float) $raw['rate'] : null;
                    if ($begin === null || $end === null || $end <= $begin) {
                        $flags[] = 'missing_or_invalid_time';
                    }
                    if ($rate === null || !is_finite($rate) || $rate < 0 || floor($rate) !== $rate) {
                        $flags[] = 'missing_rate';
                    }
                    $timed = $date !== null && $begin !== null && $end !== null && $end > $begin && $rate !== null && $rate >= 0;
                    $usable = $timed && $rate !== null && is_finite($rate) && floor($rate) === $rate && $workplace !== '' && !\in_array(mb_strtolower($workplace), ['unknown', 'ismeretlen'], true) && \in_array($category, ShiftService::CATEGORIES, true);
                    $seconds = $timed ? (int) round(($end - $begin) * 86400) : 0;
                    $result[] = [
                        'coordinate' => $sheet->getTitle() . ':' . $r . ':' . $column,
                        'date' => $date, 'workplace' => $workplace !== '' ? $workplace : ShiftService::UNKNOWN,
                        'begin' => $timed ? $date . 'T' . gmdate('H:i:s', (int) round($begin * 86400)) : '',
                        'end' => $timed ? $date . 'T' . gmdate('H:i:s', (int) round($end * 86400)) : '',
                        'rate' => $rate, 'category' => $category, 'flags' => $flags, 'usable' => $usable,
                        'source_seconds' => $seconds, 'source_amount' => $timed ? round($seconds / 3600 * $rate, 4) : 0.0,
                        'raw' => $raw,
                    ];
                }
            }
        }
        $book->disconnectWorksheets();
        if ($result === []) {
            throw new \InvalidArgumentException('shift.invalid_workbook');
        }

        return $result;
    }

    /** Converts Excel numeric or HH:MM[:SS] $value into a day fraction; returns null for blanks/formulas. */
    private function time(mixed $value): ?float
    {
        if (is_numeric($value) && (float) $value >= 0 && (float) $value < 1) {
            return (float) $value;
        }
        if (\is_string($value) && preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/D', $value, $parts) === 1) {
            $seconds = (int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) ($parts[3] ?? 0);
            if ((int) $parts[1] < 24 && (int) $parts[2] < 60 && (int) ($parts[3] ?? 0) < 60) {
                return $seconds / 86400;
            }
        }

        return null;
    }

    /** Returns scalar cell $value as text; unsupported objects/blanks become an empty source field. */
    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** Previews $path for $owner; stores normalized source rows, deduplicating the exact upload checksum. */
    public function preview(User $owner, string $path, string $filename): ShiftImportBatch
    {
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new \InvalidArgumentException('shift.invalid_workbook');
        }
        $existing = $this->em->getRepository(ShiftImportBatch::class)->findOneBy(['owner' => $owner, 'checksum' => $checksum]);
        if ($existing !== null) {
            return $existing;
        }
        $rows = $this->parse($path);
        $batch = new ShiftImportBatch($owner, $checksum, mb_substr(basename($filename), 0, 255), new \DateTimeImmutable());
        $this->em->persist($batch);
        foreach ($rows as $row) {
            $this->em->persist(new ShiftImportItem($batch, $row['coordinate'], $row));
        }
        $this->em->flush();

        return $batch;
    }

    /** Imports complete named/category items in $batch atomically; incomplete and legacy-placeholder rows stay review-only. */
    public function commit(ShiftImportBatch $batch): void
    {
        $this->em->wrapInTransaction(function () use ($batch): void {
            $this->em->refresh($batch, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if ($batch->imported) {
                return;
            }
            $places = [];
            foreach ($this->shifts->workplaces() as $place) {
                $places[$place['name']] = $place['id'];
            }
            foreach ($this->items($batch) as $item) {
                $data = $item->payload;
                if (!$data['usable'] || $data['rate'] === null || !is_finite($data['rate']) || floor($data['rate']) !== (float) $data['rate'] || !\in_array($data['category'], ShiftService::CATEGORIES, true) || \in_array(mb_strtolower($data['workplace']), ['unknown', 'ismeretlen', ''], true)) {
                    continue;
                }
                if (!isset($places[$data['workplace']])) {
                    $place = $this->shifts->workplace($data['workplace'], $data['rate'] ?? 0.0, $data['category']);
                    $places[$data['workplace']] = $place->getId();
                }
                $data['workplace'] = $places[$data['workplace']];
                $timesheet = $this->shifts->save($batch->owner, $data);
                $this->shifts->meta($timesheet, 'shift_review', implode(',', $data['flags']));
                $this->shifts->meta($timesheet, 'shift_source', $batch->checksum . ':' . $item->coordinate);
                $this->em->persist($timesheet);
                $item->timesheet = $timesheet;
                $item->resolved = $data['flags'] === [];
                $this->em->persist($item);
            }
            $batch->imported = true;
            $this->em->persist($batch);
            $this->em->flush();
        });
    }

    /** Returns $batch's source items in stable coordinate order.
     * @return list<ShiftImportItem>
     */
    public function items(ShiftImportBatch $batch): array
    {
        return $this->em->getRepository(ShiftImportItem::class)->findBy(['batch' => $batch], ['coordinate' => 'ASC']);
    }

    /** Reconciles original source controls against linked native shifts; edits remain visible as differences.
     * @return array<string, mixed>
     */
    public function reconciliation(ShiftImportBatch $batch): array
    {
        $report = ['candidates' => 0, 'imported' => 0, 'unresolved' => 0, 'flagged' => 0, 'months' => []];
        foreach ($this->items($batch) as $item) {
            $data = $item->payload;
            $report['candidates']++;
            $report['flagged'] += $data['flags'] !== [] ? 1 : 0;
            $report['imported'] += $item->timesheet !== null ? 1 : 0;
            $report['unresolved'] += !$item->resolved ? 1 : 0;
            $month = substr($data['date'] ?? 'Unknown', 0, 7);
            $report['months'][$month] ??= ['source_seconds' => 0, 'source_amount' => 0.0, 'actual_seconds' => 0, 'actual_amount' => 0.0];
            $report['months'][$month]['source_seconds'] += $data['source_seconds'];
            $report['months'][$month]['source_amount'] += $data['source_amount'];
            $report['months'][$month]['actual_seconds'] += $item->timesheet?->getDuration() ?? 0;
            $report['months'][$month]['actual_amount'] += $item->timesheet?->getRate() ?? 0;
        }
        ksort($report['months']);

        return $report;
    }
}

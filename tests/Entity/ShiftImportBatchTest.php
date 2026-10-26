<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Entity;

use App\Entity\ShiftImportBatch;
use App\Entity\ShiftImportItem;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/** Verifies import provenance defaults without private source fixtures. */
class ShiftImportBatchTest extends TestCase
{
    /** Preserves owner/checksum/coordinates and leaves new uploads unresolved and uncommitted. */
    public function testProvenanceDefaults(): void
    {
        $user = new User();
        $batch = new ShiftImportBatch($user, str_repeat('a', 64), 'test.xlsx', new \DateTimeImmutable());
        $item = new ShiftImportItem($batch, '2024.01.:2:1', ['coordinate' => '2024.01.:2:1', 'date' => null, 'workplace' => 'Unknown', 'begin' => '', 'end' => '', 'rate' => null, 'category' => 'Unknown', 'flags' => [], 'usable' => false, 'source_seconds' => 0, 'source_amount' => 0.0, 'raw' => []]);
        self::assertFalse($batch->imported);
        self::assertSame($user, $batch->owner);
        self::assertSame($batch, $item->batch);
        self::assertNull($item->timesheet);
        self::assertFalse($item->resolved);
    }
}

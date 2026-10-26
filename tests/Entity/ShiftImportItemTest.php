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

/** Checks that incomplete imported source survives without a native shift. */
class ShiftImportItemTest extends TestCase
{
    /** Keeps the exact normalized payload and source coordinate before resolution. */
    public function testRetainsIncompleteSource(): void
    {
        $batch = new ShiftImportBatch(new User(), str_repeat('b', 64), 'source.xlsx', new \DateTimeImmutable());
        $data = ['coordinate' => '2024.01.:2:1', 'date' => null, 'workplace' => 'Unknown', 'begin' => '', 'end' => '', 'rate' => null, 'category' => 'Unknown', 'flags' => ['missing_date'], 'usable' => false, 'source_seconds' => 0, 'source_amount' => 0.0, 'raw' => []];
        $item = new ShiftImportItem($batch, $data['coordinate'], $data);
        self::assertSame($data, $item->payload);
        self::assertSame($batch, $item->batch);
        self::assertNull($item->timesheet);
    }
}

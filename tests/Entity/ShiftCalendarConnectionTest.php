<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Entity;

use App\Entity\ShiftCalendarConnection;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/** Verifies new connections cannot write before setup/preview approval. */
class ShiftCalendarConnectionTest extends TestCase
{
    /** Initializes owner scope, finite dates, empty calendars and paused authorization. */
    public function testPausedDefaults(): void
    {
        $owner = new User();
        $connection = new ShiftCalendarConnection($owner);
        self::assertSame($owner, $connection->owner);
        self::assertFalse($connection->enabled);
        self::assertNull($connection->credentials);
        self::assertSame([], $connection->settings['calendars']);
        self::assertGreaterThanOrEqual($connection->settings['from'], $connection->settings['to']);
    }
}

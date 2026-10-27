<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Entity;

use App\Entity\Customer;
use App\Entity\ShiftWorkplace;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/** Verifies stable private workplace keys without removing meaningful accents. */
class ShiftWorkplaceTest extends TestCase
{
    /** Unicode composition, trimming and case normalize consistently; native IDs remain represented by their customer. */
    public function testOwnershipAndNames(): void
    {
        $owner = new User();
        $customer = new Customer('Víztorony');
        $workplace = new ShiftWorkplace($owner, $customer, ShiftWorkplace::key('Víztorony'));
        self::assertSame($owner, $workplace->owner);
        self::assertSame($customer, $workplace->customer);
        self::assertSame($workplace->nameKey, ShiftWorkplace::key(' VÍZTORONY '));
        self::assertSame(ShiftWorkplace::key("Vi\u{0301}ztorony"), $workplace->nameKey);
        self::assertNotSame(ShiftWorkplace::key('Viztorony'), $workplace->nameKey);
    }
}

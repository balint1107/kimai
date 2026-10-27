<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Command;

use App\Command\ShiftCalendarCommand;
use App\Entity\ShiftCalendarConnection;
use App\ShiftAccounting\CalendarSync\CalendarSync;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/** Verifies the background command is harmless until a user explicitly enables a connection. */
class ShiftCalendarCommandTest extends TestCase
{
    /** With no enabled connections the command performs no reconciliation and preserves empty security context. */
    public function testDisabledConnectionsDoNothing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findBy')->with(['enabled' => true])->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(ShiftCalendarConnection::class)->willReturn($repository);
        $sync = (new \ReflectionClass(CalendarSync::class))->newInstanceWithoutConstructor();
        $tokens = new TokenStorage();
        $tester = new CommandTester(new ShiftCalendarCommand($em, $sync, $tokens));
        self::assertSame(0, $tester->execute([]));
        self::assertNull($tokens->getToken());
    }

    /** Disabled owners never receive a security token or invoke the reconciler even when their connection was enabled. */
    public function testDisabledOwnerIsSkipped(): void
    {
        $connection = new ShiftCalendarConnection(new \App\Entity\User());
        $connection->enabled = true;
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$connection]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $sync = (new \ReflectionClass(CalendarSync::class))->newInstanceWithoutConstructor();
        $tokens = new TokenStorage();
        $tester = new CommandTester(new ShiftCalendarCommand($em, $sync, $tokens));
        self::assertSame(0, $tester->execute([]));
        self::assertNull($tokens->getToken());
    }
}

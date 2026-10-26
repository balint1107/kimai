<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Command;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Checks that server-side imports require an existing explicit owner. */
#[Group('integration')]
class ShiftWorkbookCommandTest extends KernelTestCase
{
    /** Rejects an unknown owner before reading any file or setting an authentication token. */
    public function testRejectsMissingOwner(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:shifts:import'));
        self::assertSame(1, $tester->execute(['path' => '/nonexistent.xlsx', 'username' => 'missing-shift-import-owner']));
        self::assertStringContainsString('Unknown user', $tester->getDisplay());
    }
}

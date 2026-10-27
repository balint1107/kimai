<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Entity\ShiftCalendarConnection;
use App\ShiftAccounting\CalendarSync\CalendarSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** One bounded synchronization pass; the deployment timer invokes it each minute without another runtime. */
#[AsCommand(name: 'app:shifts:calendar-sync', description: 'Synchronize enabled Google Calendar connections')]
final class ShiftCalendarCommand extends Command
{
    /** Injects persistence $em, reconciler $sync and $tokens for native owner permission checks. */
    public function __construct(private EntityManagerInterface $em, private CalendarSync $sync, private TokenStorageInterface $tokens)
    {
        parent::__construct();
    }

    /** Runs each enabled owner independently, restores previous security context and reports only a non-sensitive status to $output. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $previous = $this->tokens->getToken();
        $failed = false;
        try {
            foreach ($this->em->getRepository(ShiftCalendarConnection::class)->findBy(['enabled' => true]) as $connection) {
                $this->tokens->setToken(new UsernamePasswordToken($connection->owner, 'secured_area', $connection->owner->getRoles()));
                try {
                    $this->sync->run($connection);
                } catch (\Throwable) {
                    $failed = true;
                    $output->writeln('Calendar sync deferred; see connection status in the application');
                }
            }
        } finally {
            $this->tokens->setToken($previous);
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}

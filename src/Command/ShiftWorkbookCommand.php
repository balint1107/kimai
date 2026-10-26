<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Entity\User;
use App\ShiftAccounting\WorkbookImport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Supports reproducible private workbook preview/import and initializes the selected user's locale. */
#[AsCommand(name: 'app:shifts:import', description: 'Preview/import a private workbook for an existing user')]
final class ShiftWorkbookCommand extends Command
{
    /** Injects persistence, importer and token storage for native permission checks under the selected owner. */
    public function __construct(private EntityManagerInterface $em, private WorkbookImport $importer, private TokenStorageInterface $tokens)
    {
        parent::__construct();
    }

    /** Declares private workbook path, existing username and explicit commit option; preview is the default. */
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Private XLSX path')
            ->addArgument('username', InputArgument::REQUIRED, 'Existing owner username')
            ->addOption('commit', null, InputOption::VALUE_NONE, 'Import after preview');
    }

    /** Executes $input as the selected existing user, printing reconciliation JSON to $output; returns exit status. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['username' => $input->getArgument('username')]);
        } catch (\Symfony\Component\Security\Core\Exception\UserNotFoundException) {
            $user = null;
        }
        if ($user === null) {
            $output->writeln('Unknown user');

            return Command::FAILURE;
        }
        $previous = $this->tokens->getToken();
        $this->tokens->setToken(new UsernamePasswordToken($user, 'secured_area', $user->getRoles()));
        try {
            $user->setLanguage('hu');
            $user->setLocale('hu');
            $user->setTimezone('Europe/Budapest');
            $user->setPreferenceValue('login_initial_view', 'shift_calendar');
            $user->setPreferenceValue('first_day_of_week', 'monday');
            $user->setWizardAsSeen('intro');
            $user->setWizardAsSeen('profile');
            $this->em->persist($user);
            $this->em->flush();
            $path = $input->getArgument('path');
            if (!\is_string($path)) {
                throw new \InvalidArgumentException('Expected a workbook path');
            }
            $batch = $this->importer->preview($user, $path, basename($path));
            if ($input->getOption('commit')) {
                $this->importer->commit($batch);
            }
            $output->writeln(json_encode($this->importer->reconciliation($batch), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } finally {
            $this->tokens->setToken($previous);
        }

        return Command::SUCCESS;
    }
}

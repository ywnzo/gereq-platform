<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Database\Migrator;
use Gereq\Database\Seeder;
use Gereq\Setup\SetupState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Interactive client install (expects vendor + .env already present, or use bin/install).
 */
#[AsCommand(name: 'install', description: 'Interaktivní instalace / čistá DB pro nového klienta')]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Seeder $seeder,
        private readonly SetupState $setup,
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Vynutit smazání schématu')
            ->addOption('no-demo', null, InputOption::VALUE_NONE, 'Bez demo dat')
            ->addOption('no-sample', null, InputOption::VALUE_NONE, 'Bez ukázkových zákazníků')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Bez potvrzení (CI)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('GEREQ Platform — instalace klienta');

        $fresh = (bool) $input->getOption('fresh');
        $noDemo = (bool) $input->getOption('no-demo');
        $noSample = (bool) $input->getOption('no-sample');
        $yes = (bool) $input->getOption('yes');

        if (!$yes && $input->isInteractive()) {
            $fresh = $io->confirm('Čistá databáze (smazat schéma a vytvořit znovu)?', true);
            $noDemo = !$io->confirm('Načíst demo obchodní data (objednávky, produkty…)?', false);
            $noSample = !$io->confirm('Vytvořit ukázkové zákazníky?', false);
        }

        if ($fresh && !$yes && $input->isInteractive()) {
            if (!$io->confirm('Opravdu smazat všechna data v DB?', false)) {
                $io->warning('Zrušeno.');

                return Command::SUCCESS;
            }
        }

        if ($fresh) {
            $io->section('Fresh schéma');
            foreach ($this->migrator->fresh() as $line) {
                $io->writeln('  ' . $line);
            }
        } else {
            $io->section('Migrace');
            foreach ($this->migrator->migrate() as $line) {
                $io->writeln('  ' . $line);
            }
        }

        $io->section('Seed');
        $this->seeder->seed(!$noDemo, !$noSample);
        if ($fresh || ($noDemo && $noSample)) {
            $this->setup->markIncomplete();
        }
        $io->success($noDemo && $noSample
            ? 'Čistý klient připraven (admin z .env + role + moduly).'
            : 'Instalace dokončena.');

        $url = rtrim((string) env_value('APP_URL', 'http://127.0.0.1:8080'), '/');
        $email = (string) env_value('ADMIN_EMAIL', '');
        $io->listing([
            'Admin: ' . $url . '/admin',
            'Po přihlášení: průvodce nastavení ' . $url . '/admin/setup',
            'Účet: ' . ($email !== '' ? $email : '(ADMIN_EMAIL v .env)'),
            'Heslo: z .env ADMIN_PASSWORD',
            'Kořen: ' . $this->basePath,
        ]);

        return Command::SUCCESS;
    }
}

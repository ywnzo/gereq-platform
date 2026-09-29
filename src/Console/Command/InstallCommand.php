<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Console\AdminBootstrap;
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
#[AsCommand(name: 'install', description: 'Interactive install / clean DB for a new client')]
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
            ->addOption('bare', null, InputOption::VALUE_NONE, 'Jen core + admin (bez modulů / demo)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Bez potvrzení (CI)');

        foreach (AdminBootstrap::inputOptions() as [$name, $desc]) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED, $desc, '');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('GEREQ Platform — instalace klienta');

        $fresh = (bool) $input->getOption('fresh');
        $noDemo = (bool) $input->getOption('no-demo');
        $noSample = (bool) $input->getOption('no-sample');
        $bare = (bool) $input->getOption('bare');
        $yes = (bool) $input->getOption('yes');

        if (!$yes && $input->isInteractive()) {
            $fresh = $io->confirm('Čistá databáze (smazat schéma a vytvořit znovu)?', true);
            if (!$bare) {
                $noDemo = !$io->confirm('Načíst demo obchodní data (objednávky, produkty…)?', false);
                $noSample = !$io->confirm('Vytvořit ukázkové zákazníky?', false);
            }
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

        $admin = AdminBootstrap::resolve($input, $io);
        $io->section('Bootstrap');
        if ($bare) {
            $this->seeder->seedBare($admin);
        } else {
            $this->seeder->seed(!$noDemo, !$noSample, $admin);
        }
        if ($fresh || $bare || ($noDemo && $noSample)) {
            $this->setup->markIncomplete();
        }

        $io->section('Počáteční admin (neukládá se do .env)');
        $io->listing([
            'E-mail: ' . $admin['email'],
            'Jméno: ' . $admin['name'],
            'Role: ' . $admin['role'],
            $admin['generated']
                ? 'Heslo (vygenerováno — uložte si ho): ' . $admin['password']
                : 'Heslo: (zadané při instalaci)',
        ]);

        $io->success($bare
            ? 'Čistý bare klient (core + admin).'
            : ($noDemo && $noSample
                ? 'Čistý klient připraven (admin + role + moduly).'
                : 'Instalace dokončena.'));

        $url = rtrim((string) env_value('APP_URL', 'http://127.0.0.1:8080'), '/');
        $admin = \Gereq\Support\AdminPath::base();
        $io->listing([
            'Admin: ' . $url . $admin,
            'Po přihlášení: průvodce nastavení ' . $url . $admin . '/setup',
            'Kořen: ' . $this->basePath,
        ]);

        return Command::SUCCESS;
    }
}

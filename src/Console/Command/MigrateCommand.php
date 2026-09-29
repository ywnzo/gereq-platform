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

#[AsCommand(name: 'migrate', description: 'Run migrations (seed only with --seed / --bare)')]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Seeder $seeder,
        private readonly SetupState $setup,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Smazat schéma a vytvořit znovu (čistá DB)')
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Po migraci spustit seed (jinak se nespouští)')
            ->addOption('bare', null, InputOption::VALUE_NONE, 'Jen core: oprávnění, role, admin účet (žádné moduly / demo)')
            ->addOption('no-demo', null, InputOption::VALUE_NONE, 'Se seedem: bez demo obchodních dat')
            ->addOption('no-sample', null, InputOption::VALUE_NONE, 'Se seedem: bez ukázkových zákazníků');

        foreach (AdminBootstrap::inputOptions() as [$name, $desc]) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED, $desc, '');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fresh = (bool) $input->getOption('fresh');
        $doSeed = (bool) $input->getOption('seed');
        $bare = (bool) $input->getOption('bare');
        $withDemo = !(bool) $input->getOption('no-demo');
        $withSample = !(bool) $input->getOption('no-sample');

        if ($fresh) {
            $output->writeln('<comment>Fresh install — mažu schéma…</comment>');
            foreach ($this->migrator->fresh() as $message) {
                $output->writeln($message);
            }
        } else {
            foreach ($this->migrator->migrate() as $message) {
                $output->writeln($message);
            }
        }

        if ($bare) {
            $admin = AdminBootstrap::resolve($input, $io);
            $this->seeder->seedBare($admin);
            $this->setup->markIncomplete();
            $this->printAdminCredentials($io, $admin);
            $output->writeln('<comment>Bare bootstrap: oprávnění + role + admin (žádné moduly).</comment>');
            $output->writeln('<comment>Průvodce nastavení: po přihlášení ' . \Gereq\Support\AdminPath::base() . '/setup</comment>');
            $output->writeln('Hotovo (fresh schema + superadmin).');

            return Command::SUCCESS;
        }

        if (!$doSeed) {
            $output->writeln('Migrace hotové (bez seedu).');

            return Command::SUCCESS;
        }

        $admin = AdminBootstrap::resolve($input, $io);
        $this->seeder->seed($withDemo, $withSample, $admin);
        $this->printAdminCredentials($io, $admin);
        if ($fresh || (!$withDemo && !$withSample)) {
            $this->setup->markIncomplete();
            $output->writeln('<comment>Průvodce nastavení: po přihlášení ' . \Gereq\Support\AdminPath::base() . '/setup</comment>');
        }
        if (!$withDemo && !$withSample) {
            $output->writeln('Seed dokončen (čistý klient: admin + role + moduly).');
        } elseif (!$withDemo) {
            $output->writeln('Seed dokončen (bez demo dat).');
        } else {
            $output->writeln('Seed dokončen.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param array{name: string, email: string, password: string, role: string, generated: bool} $admin
     */
    private function printAdminCredentials(SymfonyStyle $io, array $admin): void
    {
        $io->section('Počáteční admin (neukládá se do .env)');
        $io->listing([
            'E-mail: ' . $admin['email'],
            'Jméno: ' . $admin['name'],
            'Role: ' . $admin['role'],
            $admin['generated']
                ? 'Heslo (vygenerováno — uložte si ho): ' . $admin['password']
                : 'Heslo: (zadané při instalaci)',
        ]);
    }
}

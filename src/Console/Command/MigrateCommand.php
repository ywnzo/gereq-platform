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

#[AsCommand(name: 'migrate', description: 'Spustit migrace a seed')]
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
            ->addOption('no-demo', null, InputOption::VALUE_NONE, 'Bez demo obchodních dat')
            ->addOption('no-sample', null, InputOption::VALUE_NONE, 'Bez ukázkových zákazníků')
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Vynutit seed (výchozí vždy běží)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fresh = (bool) $input->getOption('fresh');
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

        $this->seeder->seed($withDemo, $withSample);
        if ($fresh || (!$withDemo && !$withSample)) {
            $this->setup->markIncomplete();
            $output->writeln('<comment>Průvodce nastavení: po přihlášení /admin/setup</comment>');
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
}

<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Database\Migrator;
use Gereq\Database\Seeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate', description: 'Spustit migrace a seed')]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Seeder $seeder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->migrator->migrate() as $message) {
            $output->writeln($message);
        }

        $this->seeder->seed();
        $output->writeln('Seed dokončen.');

        return Command::SUCCESS;
    }
}

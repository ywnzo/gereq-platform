<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate:status', description: 'Stav migrací modulů')]
final class MigrateStatusCommand extends Command
{
    public function __construct(private readonly Migrator $migrator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->migrator->status() as $item) {
            $output->writeln(sprintf('%-40s %s', $item['version'], $item['ran'] ? 'ran' : 'pending'));
        }

        return Command::SUCCESS;
    }
}

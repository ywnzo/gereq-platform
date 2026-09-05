<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Database\DemoSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'seed:demo', description: 'Vytvořit demo uživatele a realistická data pro klikání')]
final class SeedDemoCommand extends Command
{
    public function __construct(private readonly DemoSeeder $demoSeeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->demoSeeder->seed() as $line) {
            $output->writeln($line);
        }

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Database\DemoSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'seed:demo', description: 'Create a demo user and realistic click-through data')]
final class SeedDemoCommand extends Command
{
    public function __construct(private readonly DemoSeeder $demoSeeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = strtolower(trim((string) env_value('APP_ENV', 'production')));
        if ($env === 'production') {
            $output->writeln('<error>Demo seed is disabled when APP_ENV=production.</error>');

            return Command::FAILURE;
        }

        try {
            foreach ($this->demoSeeder->seed() as $line) {
                $output->writeln($line);
            }
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

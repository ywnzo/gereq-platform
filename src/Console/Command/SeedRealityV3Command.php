<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Database\RealityV3Seeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'seed:realityV3', description: 'Apply realityV3 demo users, modules and emailing settings')]
final class SeedRealityV3Command extends Command
{
    public function __construct(private readonly RealityV3Seeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->seeder->seed() as $line) {
            $output->writeln($line);
        }

        return Command::SUCCESS;
    }
}

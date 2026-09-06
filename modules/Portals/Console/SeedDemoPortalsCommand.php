<?php

declare(strict_types=1);

namespace Modules\Portals\Console;

use Modules\Portals\Application\PortalDemoSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'portals:seed-demo',
    description: 'Vytvořit ukázkové portály (Sreality/Idnes/Bezrealitky) a napojení na nabídky',
)]
final class SeedDemoPortalsCommand extends Command
{
    public function __construct(private readonly PortalDemoSeeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->seeder->seed() as $line) {
            $output->writeln('<info>' . $line . '</info>');
        }

        return Command::SUCCESS;
    }
}

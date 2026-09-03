<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Module\ModuleManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'about', description: 'Informace o GEREQ Platform')]
final class AboutCommand extends Command
{
    public function __construct(private readonly ModuleManager $modules)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('GEREQ Platform ' . config('app.version', '0.1.0'));
        $output->writeln('Interní modulární backendová platforma');
        $output->writeln('');
        $output->writeln('PHP        ' . PHP_VERSION);
        $output->writeln('Prostředí  ' . config('app.env'));
        $output->writeln('Databáze   ' . config('database.connection'));
        $output->writeln('Moduly     ' . count($this->modules->discover()) . ' nalezeno, ' . count($this->modules->enabledIds()) . ' aktivních');

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Module\ModuleManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'module:enable', description: 'Aktivovat modul')]
final class ModuleEnableCommand extends Command
{
    public function __construct(private readonly ModuleManager $modules)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = strtolower((string) $input->getArgument('module'));
        $this->modules->enable($id);
        $output->writeln('Modul ' . $id . ' byl aktivován.');

        return Command::SUCCESS;
    }
}

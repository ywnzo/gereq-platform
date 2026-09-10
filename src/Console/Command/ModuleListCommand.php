<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Module\ModuleManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'module:list', description: 'List installed modules')]
final class ModuleListCommand extends Command
{
    public function __construct(private readonly ModuleManager $modules)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->modules->discover() as $manifest) {
            $state = $this->modules->isEnabled($manifest->id) ? 'enabled' : 'disabled';
            $output->writeln(sprintf('%-16s %-10s %-10s %s', $manifest->id, $manifest->version, $state, $manifest->name));
        }

        return Command::SUCCESS;
    }
}

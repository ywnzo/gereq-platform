<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Module\ModuleUninstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'module:disable', description: 'Disable a module and drop its DB tables')]
final class ModuleDisableCommand extends Command
{
    public function __construct(private readonly ModuleUninstaller $uninstaller)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('module', InputArgument::REQUIRED)
            ->addOption('purge-media', null, InputOption::VALUE_NONE, 'Smazat i související média');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = strtolower((string) $input->getArgument('module'));
        $purgeMedia = (bool) $input->getOption('purge-media');
        $result = $this->uninstaller->uninstallMany([$id], $purgeMedia);
        $output->writeln(sprintf('Deaktivováno modulů: %d.', $result['ok']));
        foreach ($result['errors'] as $line) {
            $output->writeln('<error>  ' . $line . '</error>');
        }

        return $result['errors'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}

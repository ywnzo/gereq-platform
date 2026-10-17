<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Module\ModuleAssetBuilder;
use Gereq\Module\ModuleManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'assets:ensure',
    description: 'Build lazy admin assets for enabled modules (Bun build:module)',
)]
final class AssetsEnsureCommand extends Command
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ModuleAssetBuilder $assets,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Rebuild even when already present');
        $this->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Only this module id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $only = strtolower(trim((string) $input->getOption('module')));

        $ids = $only !== ''
            ? [$only]
            : $this->modules->enabledIds();

        if ($ids === []) {
            $output->writeln('<comment>No modules to build.</comment>');

            return Command::SUCCESS;
        }

        try {
            $done = $this->assets->ensureMany($ids, $force);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($done === []) {
            $output->writeln('<comment>No module asset sources among selected modules.</comment>');

            return Command::SUCCESS;
        }

        foreach ($done as $id) {
            $output->writeln('<info>assets ok</info> ' . $id);
        }

        return Command::SUCCESS;
    }
}

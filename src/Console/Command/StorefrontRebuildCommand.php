<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Cache\StoreFrontCache;
use Gereq\Http\StorefrontRebuild;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'storefront:rebuild', description: 'Rebuild Vite storefront from configured path (local or user@host:/path)')]
final class StorefrontRebuildCommand extends Command
{
    public function __construct(
        private readonly StorefrontRebuild $rebuild,
        private readonly StoreFrontCache $storeFrontCache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Override storefront.path')
            ->addOption('command', null, InputOption::VALUE_REQUIRED, 'Override storefront.build_command');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getOption('path');
        $command = $input->getOption('command');
        $result = $this->rebuild->run(
            is_string($path) && $path !== '' ? $path : null,
            is_string($command) && $command !== '' ? $command : null,
        );

        $output->writeln(($result['remote'] ? 'remote' : 'local') . ': ' . $result['path']);
        $output->writeln('command: ' . $result['command']);
        if ($result['output'] !== '') {
            $output->writeln('');
            $output->writeln($result['output']);
        }

        if (!$result['ok']) {
            $output->writeln('<error>' . ($result['error'] ?? 'Rebuild failed') . '</error>');

            return Command::FAILURE;
        }

        $this->storeFrontCache->flush();
        $output->writeln('<info>Storefront rebuild OK — cache flushed.</info>');

        return Command::SUCCESS;
    }
}

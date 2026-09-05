<?php

declare(strict_types=1);

namespace Modules\CustomerUploads\Console;

use Modules\CustomerUploads\Application\CustomerUploadService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'uploads:purge', description: 'Smazat staré customer uploady podle retention_days')]
final class PurgeUploadsCommand extends Command
{
    public function __construct(private readonly CustomerUploadService $uploads)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_OPTIONAL, 'Override retention days');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen spočítat');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        $dry = (bool) $input->getOption('dry-run');
        $result = $this->uploads->purgeExpired(
            is_string($days) && $days !== '' ? (int) $days : null,
            $dry,
        );
        $output->writeln(sprintf(
            '%s — candidates %d, deleted %d (retention %d dní)',
            $dry ? 'DRY-RUN' : 'PURGE',
            $result['candidates'],
            $result['deleted'],
            $result['days'],
        ));

        return Command::SUCCESS;
    }
}

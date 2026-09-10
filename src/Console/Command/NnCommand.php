<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Debug\NPlusOneLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nn', description: 'List all N+1 queries from the last N minutes (full SQL)')]
final class NnCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'minutes',
            'm',
            InputOption::VALUE_REQUIRED,
            'Okno v minutách',
            '20',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        NPlusOneLog::setPath($this->basePath . '/storage/logs/nplusone.log');
        $minutes = max(1, (int) $input->getOption('minutes'));
        foreach (NPlusOneLog::formatRecent($minutes) as $line) {
            $output->writeln($line);
        }

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Schedule\ScheduleRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'schedule:run', description: 'Run due scheduled tasks (call once per minute from cron)')]
final class ScheduleRunCommand extends Command
{
    public function __construct(private readonly ScheduleRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->runner->run(output: $output);
        if ($result['ran'] === 0 && $result['skipped'] === 0 && $result['failed'] === 0) {
            $output->writeln('No scheduled tasks are due.');
        }

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}

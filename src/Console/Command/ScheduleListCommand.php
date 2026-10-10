<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Schedule\Schedule;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'schedule:list', description: 'List registered schedule entries')]
final class ScheduleListCommand extends Command
{
    public function __construct(private readonly Schedule $schedule)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $events = $this->schedule->events();
        if ($events === []) {
            $output->writeln('No scheduled tasks registered.');

            return Command::SUCCESS;
        }

        foreach ($events as $event) {
            $next = $event->nextRun();
            $output->writeln(sprintf(
                '%-18s  %-8s  %-6s  %s%s',
                $event->expression(),
                $event->type(),
                $event->runsAsync() ? 'async' : 'inline',
                $event->displayName(),
                $next !== null ? '  (next ' . $next->format('Y-m-d H:i T') . ')' : '',
            ));
            if ($event->descriptionText() !== '') {
                $output->writeln('  ' . $event->descriptionText());
            }
        }

        return Command::SUCCESS;
    }
}

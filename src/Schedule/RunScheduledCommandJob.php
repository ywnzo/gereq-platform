<?php

declare(strict_types=1);

namespace Gereq\Schedule;

use Gereq\Queue\Job;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Runs a named console command from the queue worker (async schedule entries).
 */
final class RunScheduledCommandJob implements Job
{
    /**
     * @param list<string> $arguments
     */
    public function __construct(
        private readonly string $commandName,
        private readonly array $arguments = [],
    ) {
    }

    public function commandName(): string
    {
        return $this->commandName;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function handle(): void
    {
        $class = ScheduleCommandResolver::classFor($this->commandName);
        if ($class === null || !class_exists($class)) {
            throw new \RuntimeException('Unknown scheduled command: ' . $this->commandName);
        }

        /** @var Command $command */
        $command = gereq()->make($class);
        $argv = ['command' => $this->commandName];
        foreach ($this->arguments as $argument) {
            if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
                [$key, $value] = explode('=', $argument, 2);
                $argv[$key] = $value;
            } elseif (str_starts_with($argument, '--')) {
                $argv[$argument] = true;
            } else {
                $argv[] = $argument;
            }
        }

        $exit = $command->run(new ArrayInput($argv), new NullOutput());
        if ($exit !== Command::SUCCESS) {
            throw new \RuntimeException(sprintf(
                'Scheduled command %s exited with code %d.',
                $this->commandName,
                $exit,
            ));
        }
    }
}

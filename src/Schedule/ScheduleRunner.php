<?php

declare(strict_types=1);

namespace Gereq\Schedule;

use DateTimeImmutable;
use Gereq\Queue\Job;
use Gereq\Queue\JobRun;
use Gereq\Queue\Queue;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ScheduleRunner
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ScheduleLock $locks,
        private readonly ScheduleState $state,
        private readonly Queue $queue,
    ) {
    }

    /**
     * @return array{ran: int, skipped: int, failed: int}
     */
    public function run(?DateTimeImmutable $at = null, ?OutputInterface $output = null): array
    {
        $ran = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->schedule->dueEvents($at) as $event) {
            $name = $event->mutexName();
            if ($this->state->isPaused($name)) {
                $output?->writeln('<comment>Skipped (paused): ' . $name . '</comment>');
                $this->recordSkip('schedule:' . $name, JobRun::TRIGGER_SCHEDULE, 'paused');
                $skipped++;
                continue;
            }

            $result = $this->runEvent($event, JobRun::TRIGGER_SCHEDULE, $output);
            $ran += $result['ran'];
            $skipped += $result['skipped'];
            $failed += $result['failed'];
        }

        return ['ran' => $ran, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Run one entry immediately (ignores cron due + paused). Used by admin "Run now".
     *
     * @return array{ran: int, skipped: int, failed: int}
     */
    public function runNow(string $name, ?OutputInterface $output = null): array
    {
        $event = $this->schedule->findByName($name);
        if ($event === null) {
            throw new \InvalidArgumentException('Unknown schedule entry: ' . $name);
        }

        return $this->runEvent($event, JobRun::TRIGGER_MANUAL, $output);
    }

    /**
     * @return array{ran: int, skipped: int, failed: int}
     */
    private function runEvent(ScheduledEvent $event, string $trigger, ?OutputInterface $output): array
    {
        $name = $event->mutexName();
        if ($event->preventsOverlapping()) {
            $ttl = $event->expiresInMinutes() * 60;
            if (!$this->locks->acquire($event->mutexName(), $ttl)) {
                $output?->writeln('<comment>Skipped (overlap): ' . $name . '</comment>');
                $this->recordSkip('schedule:' . $name, $trigger, 'overlap');

                return ['ran' => 0, 'skipped' => 1, 'failed' => 0];
            }
        }

        try {
            $this->execute($event, $trigger);
            $output?->writeln('<info>Ran: ' . $name . '</info>');

            return ['ran' => 1, 'skipped' => 0, 'failed' => 0];
        } catch (Throwable $e) {
            $output?->writeln('<error>Failed: ' . $name . ' — ' . $e->getMessage() . '</error>');
            error_log('GEREQ schedule: ' . $name . ' — ' . $e->getMessage());

            return ['ran' => 0, 'skipped' => 0, 'failed' => 1];
        } finally {
            if ($event->preventsOverlapping()) {
                $this->locks->release($event->mutexName());
            }
        }
    }

    private function execute(ScheduledEvent $event, string $trigger): void
    {
        if ($event->type() === 'job') {
            $job = $event->target();
            if (!$job instanceof Job) {
                throw new \RuntimeException('Schedule job target is not a Job.');
            }
            // Async onto the worker so Běhy shows the real handle() run.
            $this->queue->dispatch($job);

            return;
        }

        if ($event->type() === 'call') {
            $callback = $event->target();
            if (!is_callable($callback)) {
                throw new \RuntimeException('Schedule call target is not callable.');
            }
            $started = new DateTimeImmutable();
            $t0 = hrtime(true);
            try {
                $callback();
                $this->queue->runs()?->recordNamed(
                    'call:' . $event->mutexName(),
                    JobRun::STATUS_SUCCESS,
                    $trigger,
                    $started,
                    (int) round((hrtime(true) - $t0) / 1_000_000),
                );
            } catch (Throwable $e) {
                $this->queue->runs()?->recordNamed(
                    'call:' . $event->mutexName(),
                    JobRun::STATUS_FAILED,
                    $trigger,
                    $started,
                    (int) round((hrtime(true) - $t0) / 1_000_000),
                    $e->getMessage(),
                );
                throw $e;
            }

            return;
        }

        $commandJob = new RunScheduledCommandJob((string) $event->target(), $event->arguments());
        if ($event->runsAsync()) {
            $this->queue->dispatch($commandJob);

            return;
        }

        $this->queue->runTracked($commandJob, $trigger);
    }

    private function recordSkip(string $job, string $trigger, string $reason): void
    {
        try {
            $this->queue->runs()?->recordSkip($job, $trigger, $reason);
        } catch (Throwable $e) {
            error_log('GEREQ schedule: queue_runs skip failed: ' . $e->getMessage());
        }
    }
}

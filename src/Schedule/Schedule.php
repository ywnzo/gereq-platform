<?php

declare(strict_types=1);

namespace Gereq\Schedule;

use Gereq\Queue\Job;

/**
 * Collects recurring tasks registered by core and modules.
 * Run due entries with `php gereq schedule:run` (once per minute from cron / Docker).
 */
final class Schedule
{
    /** @var list<ScheduledEvent> */
    private array $events = [];

    /**
     * @param list<string> $arguments Extra argv tokens after the command name (e.g. ['--limit=50']).
     */
    public function command(string $name, array $arguments = []): ScheduledEvent
    {
        $event = new ScheduledEvent('command', $name, $arguments);
        $this->events[] = $event;

        return $event;
    }

    /**
     * Dispatch a job onto the queue when due (always asynchronous under redis/file).
     */
    public function job(Job $job): ScheduledEvent
    {
        $event = new ScheduledEvent('job', $job);
        $this->events[] = $event;

        return $event;
    }

    public function call(callable $callback): ScheduledEvent
    {
        $event = new ScheduledEvent('call', $callback);
        $this->events[] = $event;

        return $event;
    }

    /** @return list<ScheduledEvent> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return list<ScheduledEvent> */
    public function dueEvents(?\DateTimeImmutable $at = null): array
    {
        $due = [];
        foreach ($this->events as $event) {
            if ($event->isDue($at)) {
                $due[] = $event;
            }
        }

        return $due;
    }

    public function findByName(string $name): ?ScheduledEvent
    {
        foreach ($this->events as $event) {
            if ($event->mutexName() === $name) {
                return $event;
            }
        }

        return null;
    }
}

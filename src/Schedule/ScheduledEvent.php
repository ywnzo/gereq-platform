<?php

declare(strict_types=1);

namespace Gereq\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Gereq\Queue\Job;
use InvalidArgumentException;

/**
 * One registered schedule entry (console command, queue job or callable).
 */
final class ScheduledEvent
{
    private string $expression = '* * * * *';

    private string $timezone = 'UTC';

    private ?string $name = null;

    private string $description = '';

    private bool $withoutOverlapping = false;

    private int $expiresInMinutes = 1440;

    private bool $async = false;

    /** @var string|Job|callable */
    private readonly mixed $target;

    /**
     * @param 'command'|'job'|'call' $type
     * @param string|Job|callable $target
     * @param list<string> $arguments
     */
    public function __construct(
        private readonly string $type,
        string|Job|callable $target,
        private readonly array $arguments = [],
    ) {
        if (!in_array($type, ['command', 'job', 'call'], true)) {
            throw new InvalidArgumentException('Unknown schedule event type: ' . $type);
        }
        $this->target = $target;
    }

    public function cron(string $expression): self
    {
        $this->expression = (new CronExpression($expression))->expression();

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyTwoMinutes(): self
    {
        return $this->cron('*/2 * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    public function dailyAt(string $time): self
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) !== 1) {
            throw new InvalidArgumentException('dailyAt expects HH:MM, got: ' . $time);
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            throw new InvalidArgumentException('dailyAt out of range: ' . $time);
        }

        return $this->cron(sprintf('%d %d * * *', $minute, $hour));
    }

    public function timezone(string $timezone): self
    {
        // Validate early.
        new DateTimeZone($timezone);
        $this->timezone = $timezone;

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Skip a tick while a previous run of this event is still holding the lock.
     */
    public function withoutOverlapping(int $expiresInMinutes = 1440): self
    {
        $this->withoutOverlapping = true;
        $this->expiresInMinutes = max(1, $expiresInMinutes);

        return $this;
    }

    /**
     * Run a console command asynchronously via the job queue instead of inline in schedule:run.
     * Jobs are always async (dispatched). Callables cannot be async.
     */
    public function async(): self
    {
        if ($this->type === 'call') {
            throw new InvalidArgumentException('Callable schedule events cannot run async.');
        }
        $this->async = true;

        return $this;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return string|Job|callable */
    public function target(): mixed
    {
        return $this->target;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    public function timezoneName(): string
    {
        return $this->timezone;
    }

    public function mutexName(): string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        return match ($this->type) {
            'command' => 'command:' . (string) $this->target . ':' . implode(',', $this->arguments),
            'job' => 'job:' . ($this->target instanceof Job ? $this->target::class : 'unknown'),
            default => 'call:' . spl_object_id((object) $this),
        };
    }

    public function displayName(): string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        return match ($this->type) {
            'command' => (string) $this->target . ($this->arguments !== [] ? ' ' . implode(' ', $this->arguments) : ''),
            'job' => $this->target instanceof Job ? $this->target::class : 'job',
            default => 'call',
        };
    }

    public function descriptionText(): string
    {
        return $this->description;
    }

    public function preventsOverlapping(): bool
    {
        return $this->withoutOverlapping;
    }

    public function expiresInMinutes(): int
    {
        return $this->expiresInMinutes;
    }

    public function runsAsync(): bool
    {
        return $this->type === 'job' || $this->async;
    }

    public function isDue(?DateTimeImmutable $at = null): bool
    {
        $tz = new DateTimeZone($this->timezone);
        $at ??= new DateTimeImmutable('now', $tz);
        $at = $at->setTimezone($tz);

        return (new CronExpression($this->expression))->isDue($at);
    }

    public function nextRun(?DateTimeImmutable $after = null): ?DateTimeImmutable
    {
        $tz = new DateTimeZone($this->timezone);
        $after ??= new DateTimeImmutable('now', $tz);

        return (new CronExpression($this->expression))->nextRun($after, $tz);
    }
}

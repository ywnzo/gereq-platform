<?php

declare(strict_types=1);

namespace Gereq\Queue;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'queue_runs')]
#[ORM\Index(name: 'queue_runs_started_idx', columns: ['started_at'])]
#[ORM\Index(name: 'queue_runs_job_idx', columns: ['job'])]
#[ORM\Index(name: 'queue_runs_status_idx', columns: ['status'])]
class JobRun
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const TRIGGER_WORKER = 'worker';
    public const TRIGGER_SYNC = 'sync';
    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $job;

    #[ORM\Column(length: 180)]
    private string $queue = 'default';

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(length: 16)]
    private string $trigger;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $exception = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $payload = null;

    #[ORM\Column(name: 'duration_ms')]
    private int $durationMs = 0;

    #[ORM\Column(name: 'started_at')]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at')]
    private DateTimeImmutable $finishedAt;

    public function __construct(
        string $job,
        string $status,
        string $trigger,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
        int $durationMs = 0,
        string $queue = 'default',
        ?string $exception = null,
        ?string $payload = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->job = $job;
        $this->status = $status;
        $this->trigger = $trigger;
        $this->startedAt = $startedAt;
        $this->finishedAt = $finishedAt;
        $this->durationMs = max(0, $durationMs);
        $this->queue = $queue !== '' ? $queue : 'default';
        $this->exception = $exception;
        $this->payload = $payload;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function job(): string
    {
        return $this->job;
    }

    public function queue(): string
    {
        return $this->queue;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function trigger(): string
    {
        return $this->trigger;
    }

    public function exception(): ?string
    {
        return $this->exception;
    }

    public function payload(): ?string
    {
        return $this->payload;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): DateTimeImmutable
    {
        return $this->finishedAt;
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Queue;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'failed_jobs')]
#[ORM\Index(name: 'failed_jobs_payload_hash_idx', columns: ['payload_hash'])]
#[ORM\Index(name: 'failed_jobs_uuid_idx', columns: ['uuid'])]
class FailedJob
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    /** Stable job id across retries when known; empty for plain PHP-serialized payloads. */
    #[ORM\Column(length: 36)]
    private string $uuid = '';

    #[ORM\Column(length: 180)]
    private string $queue = 'default';

    #[ORM\Column(length: 255)]
    private string $job = '';

    #[ORM\Column(type: 'text')]
    private string $payload;

    #[ORM\Column(name: 'payload_hash', length: 64)]
    private string $payloadHash;

    #[ORM\Column(type: 'text')]
    private string $exception;

    #[ORM\Column]
    private int $attempts = 1;

    #[ORM\Column(name: 'failed_at')]
    private DateTimeImmutable $failedAt;

    public function __construct(
        string $payload,
        string $exception,
        string $queue = 'default',
        string $job = '',
        string $uuid = '',
        int    $attempts = 1,
    )
    {
        $this->id = Uuid::uuid7()->toString();
        $this->payload = $payload;
        $this->payloadHash = hash('sha256', $payload);
        $this->exception = $exception;
        $this->queue = $queue;
        $this->job = $job;
        $this->uuid = $uuid;
        $this->attempts = max(1, $attempts);
        $this->failedAt = new DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function queue(): string
    {
        return $this->queue;
    }

    public function job(): string
    {
        return $this->job;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function payloadHash(): string
    {
        return $this->payloadHash;
    }

    public function exception(): string
    {
        return $this->exception;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function failedAt(): DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function registerAttempt(string $exception, string $job = ''): void
    {
        $this->attempts++;
        $this->exception = $exception;
        $this->failedAt = new DateTimeImmutable();
        if ($job !== '') {
            $this->job = $job;
        }
    }
}

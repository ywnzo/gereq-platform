<?php

declare(strict_types=1);

namespace Gereq\Queue;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'failed_jobs')]
class FailedJob
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 180)]
    private string $queue = 'default';

    #[ORM\Column(type: 'text')]
    private string $payload;

    #[ORM\Column(type: 'text')]
    private string $exception;

    #[ORM\Column(name: 'failed_at')]
    private \DateTimeImmutable $failedAt;

    public function __construct(string $payload, string $exception, string $queue = 'default')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->payload = $payload;
        $this->exception = $exception;
        $this->queue = $queue;
        $this->failedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }
}

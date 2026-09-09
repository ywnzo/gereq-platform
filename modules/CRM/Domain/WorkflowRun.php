<?php

declare(strict_types=1);

namespace Modules\CRM\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'crm_workflow_runs')]
#[ORM\Index(name: 'idx_crm_workflow_runs_workflow', columns: ['workflow_id'])]
#[ORM\Index(name: 'idx_crm_workflow_runs_status', columns: ['status'])]
class WorkflowRun
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'workflow_id', length: 36)]
    private string $workflowId;

    #[ORM\Column(name: 'trigger_event', length: 120)]
    private string $triggerEvent;

    #[ORM\Column(name: 'trigger_payload', type: 'json')]
    private array $triggerPayload = [];

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_PENDING;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $log = [];

    #[ORM\Column(name: 'cursor_node_id', length: 64, nullable: true)]
    private ?string $cursorNodeId = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'started_at', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $workflowId, string $triggerEvent, array $triggerPayload)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->workflowId = $workflowId;
        $this->triggerEvent = $triggerEvent;
        $this->triggerPayload = $triggerPayload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function workflowId(): string
    {
        return $this->workflowId;
    }

    public function triggerEvent(): string
    {
        return $this->triggerEvent;
    }

    /** @return array<string, mixed> */
    public function triggerPayload(): array
    {
        return $this->triggerPayload;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return list<array<string, mixed>> */
    public function log(): array
    {
        return $this->log;
    }

    public function cursorNodeId(): ?string
    {
        return $this->cursorNodeId;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function markRunning(?string $cursor = null): void
    {
        $this->status = self::STATUS_RUNNING;
        $this->startedAt ??= new \DateTimeImmutable();
        if ($cursor !== null) {
            $this->cursorNodeId = $cursor;
        }
    }

    public function markWaiting(string $cursor): void
    {
        $this->status = self::STATUS_WAITING;
        $this->cursorNodeId = $cursor;
    }

    public function markCompleted(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->finishedAt = new \DateTimeImmutable();
        $this->errorMessage = null;
    }

    public function markFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $error;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /** @param array<string, mixed> $entry */
    public function appendLog(array $entry): void
    {
        $entry['at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->log[] = $entry;
    }

    public function setCursor(?string $nodeId): void
    {
        $this->cursorNodeId = $nodeId;
    }
}

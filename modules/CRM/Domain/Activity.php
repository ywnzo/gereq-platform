<?php

declare(strict_types=1);

namespace Modules\CRM\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'crm_activities')]
#[ORM\Index(name: 'idx_crm_activities_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_crm_activities_deal', columns: ['deal_id'])]
#[ORM\Index(name: 'idx_crm_activities_type', columns: ['type'])]
class Activity
{
    public const TYPES = ['call', 'email', 'meeting', 'task', 'note'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(name: 'deal_id', length: 36, nullable: true)]
    private ?string $dealId = null;

    #[ORM\Column(length: 32)]
    private string $type = 'note';

    #[ORM\Column(length: 190)]
    private string $subject;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(name: 'due_at', nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(name: 'completed_at', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $customerId, string $subject, string $type = 'note')
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Neplatný typ aktivity.');
        }

        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->subject = $subject;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function dealId(): ?string
    {
        return $this->dealId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function dueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isCompleted(): bool
    {
        return $this->completedAt !== null;
    }

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
        $this->touch();
    }

    public function setDealId(?string $dealId): void
    {
        $this->dealId = $dealId !== '' ? $dealId : null;
        $this->touch();
    }

    public function setType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Neplatný typ aktivity.');
        }
        $this->type = $type;
        $this->touch();
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
        $this->touch();
    }

    public function setBody(?string $body): void
    {
        $this->body = $body !== '' ? $body : null;
        $this->touch();
    }

    public function setDueAt(?\DateTimeImmutable $dueAt): void
    {
        $this->dueAt = $dueAt;
        $this->touch();
    }

    public function complete(): void
    {
        $this->completedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function reopen(): void
    {
        $this->completedAt = null;
        $this->touch();
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'call' => 'crm.activity.type.call',
            'email' => 'crm.activity.type.email',
            'meeting' => 'crm.activity.type.meeting',
            'task' => 'crm.activity.type.task',
            'note' => 'crm.activity.type.note',
            default => $this->type,
        };
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

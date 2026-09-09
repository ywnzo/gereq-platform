<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_conversations')]
#[ORM\Index(name: 'idx_ai_conv_agent', columns: ['agent_id', 'created_at'])]
class AiConversation
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'agent_id', length: 36)]
    private string $agentId;

    #[ORM\Column(length: 20)]
    private string $surface;

    #[ORM\Column(name: 'actor_type', length: 20)]
    private string $actorType;

    #[ORM\Column(name: 'actor_id', length: 36, nullable: true)]
    private ?string $actorId = null;

    #[ORM\Column(name: 'entity_type', length: 80, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(name: 'entity_id', length: 36, nullable: true)]
    private ?string $entityId = null;

    #[ORM\Column(length: 20)]
    private string $status = 'open';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $agentId,
        string $surface,
        string $actorType,
        ?string $actorId = null,
        ?string $entityType = null,
        ?string $entityId = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->agentId = $agentId;
        $this->surface = $surface;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function agentId(): string
    {
        return $this->agentId;
    }

    public function surface(): string
    {
        return $this->surface;
    }

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function entityType(): ?string
    {
        return $this->entityType;
    }

    public function entityId(): ?string
    {
        return $this->entityId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function close(): void
    {
        $this->status = 'closed';
        $this->touch();
    }
}

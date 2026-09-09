<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_feedback')]
#[ORM\Index(name: 'idx_ai_feedback_conv', columns: ['conversation_id', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_ai_feedback_message', columns: ['message_id'])]
class AiFeedback
{
    public const RATINGS = ['positive', 'negative'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'conversation_id', length: 36)]
    private string $conversationId;

    #[ORM\Column(name: 'message_id', length: 36)]
    private string $messageId;

    #[ORM\Column(name: 'agent_id', length: 36)]
    private string $agentId;

    #[ORM\Column(length: 20)]
    private string $rating;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'actor_type', length: 20)]
    private string $actorType;

    #[ORM\Column(name: 'actor_id', length: 36, nullable: true)]
    private ?string $actorId = null;

    #[ORM\Column(name: 'learned_source_id', length: 36, nullable: true)]
    private ?string $learnedSourceId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $conversationId,
        string $messageId,
        string $agentId,
        string $rating,
        string $actorType,
        ?string $actorId = null,
        ?string $note = null,
    ) {
        if (!in_array($rating, self::RATINGS, true)) {
            throw new \InvalidArgumentException('Invalid feedback rating.');
        }
        $this->id = Uuid::uuid7()->toString();
        $this->conversationId = $conversationId;
        $this->messageId = $messageId;
        $this->agentId = $agentId;
        $this->rating = $rating;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function conversationId(): string
    {
        return $this->conversationId;
    }

    public function messageId(): string
    {
        return $this->messageId;
    }

    public function agentId(): string
    {
        return $this->agentId;
    }

    public function rating(): string
    {
        return $this->rating;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function learnedSourceId(): ?string
    {
        return $this->learnedSourceId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPositive(): bool
    {
        return $this->rating === 'positive';
    }

    public function setRating(string $rating): void
    {
        if (!in_array($rating, self::RATINGS, true)) {
            throw new \InvalidArgumentException('Invalid feedback rating.');
        }
        $this->rating = $rating;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function setLearnedSourceId(?string $learnedSourceId): void
    {
        $this->learnedSourceId = $learnedSourceId;
    }
}

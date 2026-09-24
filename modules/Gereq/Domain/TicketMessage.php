<?php

declare(strict_types=1);

namespace Modules\Gereq\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'gereq_ticket_messages')]
#[ORM\Index(name: 'idx_gereq_ticket_messages_ticket', columns: ['ticket_id'])]
class TicketMessage
{
    public const DIRECTIONS = ['visitor', 'agent', 'internal', 'system'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'ticket_id', length: 36)]
    private string $ticketId;

    #[ORM\Column(length: 32)]
    private string $direction;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'is_internal', options: ['default' => false])]
    private bool $isInternal = false;

    #[ORM\Column(name: 'is_system', options: ['default' => false])]
    private bool $isSystem = false;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $attachments = [];

    #[ORM\Column(name: 'user_id', length: 36, nullable: true)]
    private ?string $userId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @param list<array<string, mixed>> $attachments */
    public function __construct(string $ticketId, string $direction, string $body, ?string $userId = null, array $attachments = [])
    {
        $this->id = Uuid::uuid4()->toString();
        $this->ticketId = $ticketId;
        $this->direction = in_array($direction, self::DIRECTIONS, true) ? $direction : 'visitor';
        $this->body = $body;
        $this->userId = $userId;
        $this->attachments = $attachments;
        $this->isInternal = $direction === 'internal';
        $this->isSystem = $direction === 'system';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string { return $this->id; }
    public function ticketId(): string { return $this->ticketId; }
    public function direction(): string { return $this->direction; }
    public function body(): string { return $this->body; }
    public function isInternal(): bool { return $this->isInternal; }
    public function isSystem(): bool { return $this->isSystem; }
    /** @return list<array<string, mixed>> */
    public function attachments(): array { return $this->attachments; }
    public function userId(): ?string { return $this->userId; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
}

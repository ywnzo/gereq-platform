<?php

declare(strict_types=1);

namespace Modules\Gereq\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'gereq_tickets')]
#[ORM\UniqueConstraint(name: 'uniq_gereq_ticket_visitor', columns: ['visitor_token'])]
#[ORM\Index(name: 'idx_gereq_tickets_site_status', columns: ['ticket_site_id', 'status'])]
class Ticket
{
    public const STATUSES = ['open', 'pending', 'resolved', 'closed'];
    public const CHANNELS = ['widget', 'email', 'api'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'ticket_site_id', length: 36, nullable: true)]
    private ?string $ticketSiteId = null;

    #[ORM\Column(length: 32)]
    private string $number;

    #[ORM\Column(name: 'visitor_token', length: 64)]
    private string $visitorToken;

    #[ORM\Column(name: 'visitor_name', length: 120, nullable: true)]
    private ?string $visitorName = null;

    #[ORM\Column(name: 'visitor_email', length: 180, nullable: true)]
    private ?string $visitorEmail = null;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(length: 32, options: ['default' => 'open'])]
    private string $status = 'open';

    #[ORM\Column(length: 32, options: ['default' => 'widget'])]
    private string $channel = 'widget';

    #[ORM\Column(length: 32, options: ['default' => 'normal'])]
    private string $priority = 'normal';

    #[ORM\Column(name: 'assigned_user_id', length: 36, nullable: true)]
    private ?string $assignedUserId = null;

    #[ORM\Column(name: 'is_unread', options: ['default' => true])]
    private bool $isUnread = true;

    #[ORM\Column(name: 'source_url', length: 2048, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(name: 'last_message_at', nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column(name: 'visitor_last_seen_at', nullable: true)]
    private ?\DateTimeImmutable $visitorLastSeenAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $rating = null;

    #[ORM\Column(name: 'rating_comment', type: 'text', nullable: true)]
    private ?string $ratingComment = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $tags = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $number, string $visitorToken, ?string $ticketSiteId = null)
    {
        $this->id = Uuid::uuid4()->toString();
        $this->number = $number;
        $this->visitorToken = $visitorToken;
        $this->ticketSiteId = $ticketSiteId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->lastMessageAt = $this->createdAt;
    }

    public function id(): string { return $this->id; }
    public function ticketSiteId(): ?string { return $this->ticketSiteId; }
    public function number(): string { return $this->number; }
    public function visitorToken(): string { return $this->visitorToken; }
    public function visitorName(): ?string { return $this->visitorName; }
    public function visitorEmail(): ?string { return $this->visitorEmail; }
    public function subject(): ?string { return $this->subject; }
    public function status(): string { return $this->status; }
    public function channel(): string { return $this->channel; }
    public function priority(): string { return $this->priority; }
    public function assignedUserId(): ?string { return $this->assignedUserId; }
    public function isUnread(): bool { return $this->isUnread; }
    public function sourceUrl(): ?string { return $this->sourceUrl; }
    public function lastMessageAt(): ?\DateTimeImmutable { return $this->lastMessageAt; }
    public function visitorLastSeenAt(): ?\DateTimeImmutable { return $this->visitorLastSeenAt; }
    public function rating(): ?int { return $this->rating; }
    public function ratingComment(): ?string { return $this->ratingComment; }
    /** @return list<string> */
    public function tags(): array { return $this->tags; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setVisitorName(?string $v): void { $this->visitorName = $v !== null ? mb_substr(trim($v), 0, 120) : null; $this->touch(); }
    public function setVisitorEmail(?string $v): void { $this->visitorEmail = $v !== null ? mb_strtolower(mb_substr(trim($v), 0, 180)) : null; $this->touch(); }
    public function setSubject(?string $v): void { $this->subject = $v !== null ? mb_substr(trim($v), 0, 190) : null; $this->touch(); }
    public function setStatus(string $s): void { $this->status = in_array($s, self::STATUSES, true) ? $s : $this->status; $this->touch(); }
    public function setChannel(string $c): void { $this->channel = in_array($c, self::CHANNELS, true) ? $c : $this->channel; $this->touch(); }
    public function setPriority(string $p): void { $this->priority = in_array($p, self::PRIORITIES, true) ? $p : $this->priority; $this->touch(); }
    public function setAssignedUserId(?string $id): void { $this->assignedUserId = $id; $this->touch(); }
    public function setUnread(bool $v): void { $this->isUnread = $v; $this->touch(); }
    public function setSourceUrl(?string $u): void { $this->sourceUrl = $u !== null ? mb_substr($u, 0, 2048) : null; $this->touch(); }
    public function touchLastMessage(): void { $this->lastMessageAt = new \DateTimeImmutable(); $this->touch(); }
    public function touchVisitorSeen(): void { $this->visitorLastSeenAt = new \DateTimeImmutable(); $this->touch(); }
    public function setRating(?int $r, ?string $comment = null): void
    {
        $this->rating = $r !== null ? max(1, min(5, $r)) : null;
        $this->ratingComment = $comment;
        $this->touch();
    }
    /** @param list<string> $tags */
    public function setTags(array $tags): void { $this->tags = array_values($tags); $this->touch(); }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}

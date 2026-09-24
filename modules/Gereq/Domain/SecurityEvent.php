<?php

declare(strict_types=1);

namespace Modules\Gereq\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'gereq_security_events')]
#[ORM\Index(name: 'idx_gereq_sec_events_site_at', columns: ['security_site_id', 'occurred_at'])]
class SecurityEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'security_site_id', length: 36)]
    private string $securitySiteId;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'visitor_hash', length: 64)]
    private string $visitorHash;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $path = null;

    #[ORM\Column(type: 'integer')]
    private int $score = 0;

    #[ORM\Column(length: 32)]
    private string $action;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $reasons = [];

    #[ORM\Column(name: 'user_agent', length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @param list<string> $reasons */
    public function __construct(
        string $securitySiteId,
        string $visitorHash,
        int $score,
        string $action,
        array $reasons = [],
        ?string $path = null,
        ?string $userAgent = null,
    ) {
        $this->id = Uuid::uuid4()->toString();
        $this->securitySiteId = $securitySiteId;
        $this->occurredAt = new \DateTimeImmutable();
        $this->visitorHash = $visitorHash;
        $this->score = max(0, min(100, $score));
        $this->action = $action;
        $this->reasons = $reasons;
        $this->path = $path !== null && $path !== '' ? mb_substr($path, 0, 500) : null;
        $this->userAgent = $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null;
        $this->createdAt = $this->occurredAt;
    }

    public function id(): string { return $this->id; }
    public function securitySiteId(): string { return $this->securitySiteId; }
    public function occurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function visitorHash(): string { return $this->visitorHash; }
    public function path(): ?string { return $this->path; }
    public function score(): int { return $this->score; }
    public function action(): string { return $this->action; }
    /** @return list<string> */
    public function reasons(): array { return $this->reasons; }
    public function userAgent(): ?string { return $this->userAgent; }
}

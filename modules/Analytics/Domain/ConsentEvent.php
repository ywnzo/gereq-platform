<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Consent audit trail (`analytics_consent_events`), written by the public consent endpoint.
 *
 * The row records the decision and the CMP categories as reported at that moment; `visitor_hash`
 * is filled only when the client still sent its pseudonymous id, so a grant before the first hit
 * stays anonymous. Consent is the legal basis for everything else in this module.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_consent_events')]
#[ORM\Index(name: 'idx_analytics_consent_events_site_occurred', columns: ['site_id', 'occurred_at'])]
class ConsentEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'site_id', length: 36)]
    private string $siteId;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 20)]
    private string $decision;

    /** @var array<string, bool> CMP category => granted map as reported by the client. */
    #[ORM\Column(type: 'json')]
    private array $categories;

    #[ORM\Column(name: 'visitor_hash', length: 64, nullable: true)]
    private ?string $visitorHash;

    /** @param array<string, bool> $categories */
    private function __construct(
        string $siteId,
        \DateTimeImmutable $occurredAt,
        string $decision,
        array $categories,
        ?string $visitorHash,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->siteId = $siteId;
        $this->occurredAt = $occurredAt;
        $this->decision = $decision;
        $this->categories = $categories;
        $this->visitorHash = $visitorHash;
    }

    /**
     * Record a consent decision.
     *
     * @param string $decision One of {@see ConsentDecision::ALL}.
     * @param array<string, bool> $categories CMP categories as sent with the decision.
     * @param string|null $visitorHash Pseudonymous visitor id when the client still had one.
     *
     * @throws \InvalidArgumentException When the decision is not one of the three known values.
     */
    public static function create(
        string $siteId,
        \DateTimeImmutable $occurredAt,
        string $decision,
        array $categories = [],
        ?string $visitorHash = null,
    ): self {
        $decision = trim($decision);
        if (!ConsentDecision::isKnown($decision)) {
            throw new \InvalidArgumentException('analytics.error.invalid_consent_decision');
        }

        $visitorHash = trim((string) $visitorHash);

        return new self(
            $siteId,
            $occurredAt,
            $decision,
            $categories,
            $visitorHash !== '' ? mb_substr($visitorHash, 0, 64) : null,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function siteId(): string
    {
        return $this->siteId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function decision(): string
    {
        return $this->decision;
    }

    /** @return array<string, bool> */
    public function categories(): array
    {
        return $this->categories;
    }

    public function visitorHash(): ?string
    {
        return $this->visitorHash;
    }

    public function isRevoked(): bool
    {
        return $this->decision === ConsentDecision::REVOKED;
    }
}

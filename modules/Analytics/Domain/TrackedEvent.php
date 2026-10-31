<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Tracked event (`analytics_events`): clicks, engagement beacons and custom `track()` calls.
 *
 * `name` is free-form on purpose (the tracker reports the name as sent), `value` is the optional
 * numeric payload and `props` keeps the raw JSON for the later heatmap/funnel phase.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_events')]
#[ORM\Index(name: 'idx_analytics_events_site_occurred', columns: ['site_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_analytics_events_site_name_occurred', columns: ['site_id', 'name', 'occurred_at'])]
class TrackedEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'site_id', length: 36)]
    private string $siteId;

    #[ORM\Column(name: 'session_id', length: 36)]
    private string $sessionId;

    #[ORM\Column(name: 'visitor_id', length: 36)]
    private string $visitorId;

    #[ORM\Column(name: 'pageview_id', length: 36, nullable: true)]
    private ?string $pageviewId;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(nullable: true)]
    private ?float $value;

    /** @var array<string, mixed> Raw tracker payload (click coordinates, engaged_ms, …). */
    #[ORM\Column(type: 'json')]
    private array $props;

    /** @param array<string, mixed> $props */
    private function __construct(
        string $siteId,
        string $sessionId,
        string $visitorId,
        string $name,
        \DateTimeImmutable $occurredAt,
        ?string $pageviewId,
        ?float $value,
        array $props,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->siteId = $siteId;
        $this->sessionId = $sessionId;
        $this->visitorId = $visitorId;
        $this->name = $name;
        $this->occurredAt = $occurredAt;
        $this->pageviewId = $pageviewId;
        $this->value = $value;
        $this->props = $props;
    }

    /**
     * Record one event of a session.
     *
     * @param string|null $pageviewId Pageview the event belongs to, when the tracker sent one.
     * @param float|null $value Optional numeric payload (order value, scroll depth, …).
     * @param array<string, mixed> $props Raw tracker payload stored as JSON.
     *
     * @throws \InvalidArgumentException When the event name is empty.
     */
    public static function create(
        string $siteId,
        string $sessionId,
        string $visitorId,
        \DateTimeImmutable $occurredAt,
        string $name,
        ?string $pageviewId = null,
        ?float $value = null,
        array $props = [],
    ): self {
        $name = self::clip($name, 120);
        if ($name === '') {
            throw new \InvalidArgumentException('analytics.error.event_name_required');
        }

        return new self(
            $siteId,
            $sessionId,
            $visitorId,
            $name,
            $occurredAt,
            self::nullable($pageviewId, 36),
            $value,
            $props,
        );
    }

    public function setValue(?float $value): void
    {
        $this->value = $value;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function siteId(): string
    {
        return $this->siteId;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function visitorId(): string
    {
        return $this->visitorId;
    }

    public function pageviewId(): ?string
    {
        return $this->pageviewId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function value(): ?float
    {
        return $this->value;
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        return $this->props;
    }

    private static function nullable(?string $value, int $max): ?string
    {
        $value = $value !== null ? self::clip($value, $max) : '';

        return $value !== '' ? $value : null;
    }

    /** Keep a stored string within its column capacity so collection cannot fail on long input. */
    private static function clip(string $value, int $max): string
    {
        $value = trim($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}

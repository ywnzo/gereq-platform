<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Single pageview (`analytics_pageviews`).
 *
 * Heatmap data (click coordinates, scroll depth, engaged time) is collected from day one:
 * generic payloads live in `props` and the engaged time is denormalised into `engaged_seconds`
 * so reports do not have to touch the event table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_pageviews')]
#[ORM\Index(name: 'idx_analytics_pageviews_site_occurred', columns: ['site_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_analytics_pageviews_session', columns: ['session_id'])]
#[ORM\Index(name: 'idx_analytics_pageviews_site_path', columns: ['site_id', 'path'])]
#[ORM\Index(name: 'idx_analytics_pageviews_visitor', columns: ['visitor_id'])]
class Pageview
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

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title;

    #[ORM\Column(name: 'referrer_host', length: 190, nullable: true)]
    private ?string $referrerHost;

    #[ORM\Column(name: 'is_entry')]
    private bool $isEntry = false;

    #[ORM\Column(name: 'is_exit')]
    private bool $isExit = false;

    #[ORM\Column(name: 'engaged_seconds')]
    private int $engagedSeconds = 0;

    #[ORM\Column(name: 'viewport_width', nullable: true)]
    private ?int $viewportWidth = null;

    #[ORM\Column(name: 'viewport_height', nullable: true)]
    private ?int $viewportHeight = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $language = null;

    /** @var array<string, mixed> Tracker payload extras (scroll depth, click coordinates, …). */
    #[ORM\Column(type: 'json')]
    private array $props;

    /** @param array<string, mixed> $props */
    private function __construct(
        string $siteId,
        string $sessionId,
        string $visitorId,
        \DateTimeImmutable $occurredAt,
        string $path,
        bool $isEntry,
        array $props,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->siteId = $siteId;
        $this->sessionId = $sessionId;
        $this->visitorId = $visitorId;
        $this->occurredAt = $occurredAt;
        $this->path = $path;
        $this->isEntry = $isEntry;
        $this->props = $props;
    }

    /**
     * Record a pageview beacon.
     *
     * @param string|null $title Document title as sent by the tracker.
     * @param string|null $referrerHost Referrer host only — full referrer URLs are never stored.
     * @param bool $isEntry Whether this pageview opened the session.
     * @param array<string, mixed> $props Extra tracker payload; keep it small, it is stored as JSON.
     */
    public static function create(
        string $siteId,
        string $sessionId,
        string $visitorId,
        \DateTimeImmutable $occurredAt,
        string $path,
        ?string $title = null,
        ?string $referrerHost = null,
        bool $isEntry = false,
        ?int $viewportWidth = null,
        ?int $viewportHeight = null,
        ?string $language = null,
        array $props = [],
    ): self {
        $pageview = new self(
            $siteId,
            $sessionId,
            $visitorId,
            $occurredAt,
            self::clip($path, 255),
            $isEntry,
            $props,
        );
        $pageview->title = self::nullable($title, 255);
        $pageview->referrerHost = self::nullable($referrerHost, 190);
        $pageview->viewportWidth = self::viewportDimension($viewportWidth);
        $pageview->viewportHeight = self::viewportDimension($viewportHeight);
        $pageview->language = self::nullable($language, 12);

        return $pageview;
    }

    /** Add a piece of measured engagement time (deltas reported by the tracker). */
    public function addEngagedSeconds(int $seconds): void
    {
        if ($seconds > 0) {
            $this->engagedSeconds += $seconds;
        }
    }

    /** Replace the engaged time (when the tracker reports a total instead of a delta). */
    public function setEngagedSeconds(int $seconds): void
    {
        $this->engagedSeconds = max(0, $seconds);
    }

    /** Mark (or unmark) this pageview as the last one of its session. */
    public function markExit(bool $isExit = true): void
    {
        $this->isExit = $isExit;
    }

    public function setTitle(?string $title): void
    {
        $this->title = self::nullable($title, 255);
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

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function referrerHost(): ?string
    {
        return $this->referrerHost;
    }

    public function isEntry(): bool
    {
        return $this->isEntry;
    }

    public function isExit(): bool
    {
        return $this->isExit;
    }

    public function engagedSeconds(): int
    {
        return $this->engagedSeconds;
    }

    public function viewportWidth(): ?int
    {
        return $this->viewportWidth;
    }

    public function viewportHeight(): ?int
    {
        return $this->viewportHeight;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        return $this->props;
    }

    /** A viewport of zero or less is "unknown", not a real measurement. */
    private static function viewportDimension(?int $value): ?int
    {
        return $value !== null && $value > 0 ? $value : null;
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

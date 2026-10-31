<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Server-side visit session (`analytics_sessions`).
 *
 * Sessionization is never trusted to the client: the ingest service opens a session on the first
 * hit and starts a new one when the visitor has no open session or the gap since
 * `last_activity_at` exceeds `analytics.session_idle_minutes`. Counters are maintained here so
 * ingest only has to call {@see VisitSession::registerPageview()} / {@see VisitSession::recordEvent()}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_sessions')]
#[ORM\Index(name: 'idx_analytics_sessions_site_started', columns: ['site_id', 'started_at'])]
#[ORM\Index(name: 'idx_analytics_sessions_site_activity', columns: ['site_id', 'last_activity_at'])]
#[ORM\Index(name: 'idx_analytics_sessions_visitor', columns: ['visitor_id'])]
class VisitSession
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'site_id', length: 36)]
    private string $siteId;

    #[ORM\Column(name: 'visitor_id', length: 36)]
    private string $visitorId;

    #[ORM\Column(name: 'started_at')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'last_activity_at')]
    private \DateTimeImmutable $lastActivityAt;

    #[ORM\Column(name: 'ended_at', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'pageview_count')]
    private int $pageviewCount = 0;

    #[ORM\Column(name: 'event_count')]
    private int $eventCount = 0;

    #[ORM\Column(name: 'duration_seconds')]
    private int $durationSeconds = 0;

    #[ORM\Column(name: 'is_bounce')]
    private bool $isBounce = true;

    #[ORM\Column(name: 'entry_path', length: 255)]
    private string $entryPath;

    #[ORM\Column(name: 'exit_path', length: 255)]
    private string $exitPath;

    #[ORM\Column(name: 'entry_referrer_host', length: 190, nullable: true)]
    private ?string $entryReferrerHost;

    #[ORM\Column(length: 20)]
    private string $source;

    /** @var array<string, string> Raw `utm_*` parameters of the entry hit. */
    #[ORM\Column(type: 'json')]
    private array $utm;

    #[ORM\Column(name: 'device_type', length: 20)]
    private string $deviceType;

    #[ORM\Column(length: 40)]
    private string $browser;

    #[ORM\Column(length: 40)]
    private string $os;

    /** @param array<string, string> $utm */
    private function __construct(
        string $siteId,
        string $visitorId,
        \DateTimeImmutable $startedAt,
        string $entryPath,
        ?string $entryReferrerHost,
        string $source,
        array $utm,
        string $deviceType,
        string $browser,
        string $os,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->siteId = $siteId;
        $this->visitorId = $visitorId;
        $this->startedAt = $startedAt;
        $this->lastActivityAt = $startedAt;
        $this->entryPath = $entryPath;
        $this->exitPath = $entryPath;
        $this->entryReferrerHost = $entryReferrerHost;
        $this->source = $source;
        $this->utm = $utm;
        $this->deviceType = $deviceType;
        $this->browser = $browser;
        $this->os = $os;
    }

    /**
     * Open a session for the first pageview of a visit.
     *
     * @param string $entryPath Path of the entry hit; used as the initial exit path too.
     * @param string $source Traffic source class, see {@see TrafficSource}.
     * @param array<string, string> $utm Raw `utm_*` parameters of the entry hit.
     */
    public static function create(
        string $siteId,
        string $visitorId,
        \DateTimeImmutable $startedAt,
        string $entryPath,
        ?string $entryReferrerHost = null,
        string $source = TrafficSource::DIRECT,
        array $utm = [],
        string $deviceType = '',
        string $browser = '',
        string $os = '',
    ): self {
        $path = self::clip($entryPath, 255);
        $source = self::clip($source, 20);

        return new self(
            $siteId,
            $visitorId,
            $startedAt,
            $path,
            self::nullable($entryReferrerHost, 190),
            $source !== '' ? $source : TrafficSource::DIRECT,
            self::cleanUtm($utm),
            self::clip($deviceType, 20),
            self::clip($browser, 40),
            self::clip($os, 40),
        );
    }

    /**
     * Register one pageview of this session.
     *
     * The session stops being a bounce once it holds a second pageview or any tracked event; the
     * exit path always follows the newest pageview.
     *
     * @throws \LogicException When the session is already closed.
     */
    public function registerPageview(\DateTimeImmutable $now, string $path, bool $isEntry = false): void
    {
        $this->assertOpen();

        $path = self::clip($path, 255);
        $this->pageviewCount++;
        if ($isEntry) {
            $this->entryPath = $path;
        }
        if ($path !== '') {
            $this->exitPath = $path;
        }
        $this->lastActivityAt = $now;
        $this->refreshBounce();
        $this->refreshDuration();
    }

    /**
     * Register one tracked event (click, engagement, custom) that belongs to this session.
     *
     * @throws \LogicException When the session is already closed.
     */
    public function recordEvent(\DateTimeImmutable $now): void
    {
        $this->assertOpen();

        $this->eventCount++;
        $this->lastActivityAt = $now;
        $this->refreshBounce();
        $this->refreshDuration();
    }

    /**
     * Force the stored bounce flag, e.g. when ingest re-evaluates the session at close time.
     * The derived rule matches `Application\Sessionizer::isBounce()`.
     */
    public function setBounce(bool $isBounce): void
    {
        $this->isBounce = $isBounce;
    }

    /**
     * Close the session. The session always ends at the last observed activity: closing later
     * (idle-window detection, consent revocation) must not inflate `duration_seconds` with the
     * idle gap. Idempotent — revocation and the sessionizer may both try to close it.
     *
     * @param \DateTimeImmutable $now Time the end was observed, used as an upper bound only.
     */
    public function close(\DateTimeImmutable $now): void
    {
        if ($this->endedAt !== null) {
            return;
        }

        $this->endedAt = $now < $this->lastActivityAt ? $now : $this->lastActivityAt;
        $this->refreshDuration();
    }

    /** True while the visitor can still extend this session. */
    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function siteId(): string
    {
        return $this->siteId;
    }

    public function visitorId(): string
    {
        return $this->visitorId;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function lastActivityAt(): \DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function endedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function pageviewCount(): int
    {
        return $this->pageviewCount;
    }

    public function eventCount(): int
    {
        return $this->eventCount;
    }

    public function durationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function isBounce(): bool
    {
        return $this->isBounce;
    }

    public function entryPath(): string
    {
        return $this->entryPath;
    }

    public function exitPath(): string
    {
        return $this->exitPath;
    }

    public function entryReferrerHost(): ?string
    {
        return $this->entryReferrerHost;
    }

    public function source(): string
    {
        return $this->source;
    }

    /** @return array<string, string> */
    public function utm(): array
    {
        return $this->utm;
    }

    public function deviceType(): string
    {
        return $this->deviceType;
    }

    public function browser(): string
    {
        return $this->browser;
    }

    public function os(): string
    {
        return $this->os;
    }

    private function assertOpen(): void
    {
        if ($this->endedAt !== null) {
            throw new \LogicException('analytics.error.session_closed');
        }
    }

    /** `duration_seconds` is derived, never accumulated, so it stays consistent after every touch. */
    private function refreshDuration(): void
    {
        $end = $this->endedAt ?? $this->lastActivityAt;
        $this->durationSeconds = max(0, $end->getTimestamp() - $this->startedAt->getTimestamp());
    }

    /** Single pageview and no interaction — the same rule as `Application\Sessionizer::isBounce()`. */
    private function refreshBounce(): void
    {
        $this->isBounce = $this->pageviewCount <= 1 && $this->eventCount === 0;
    }

    /**
     * Keep only scalar `utm_*` values, trimmed and clipped to the JSON-friendly column size.
     *
     * @param array<string, string> $utm
     * @return array<string, string>
     */
    private static function cleanUtm(array $utm): array
    {
        $out = [];
        foreach ($utm as $key => $value) {
            $key = self::clip((string) $key, 40);
            $value = self::clip((string) $value, 190);
            if ($key !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
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

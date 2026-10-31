<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Per-day site totals (`analytics_daily_stats`).
 *
 * Written by `Application\RollupService`, which deletes the day's row set and inserts a fresh one,
 * so the entity only needs to accumulate counters and normalise `day` to midnight.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_daily_stats')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_daily_stats_site_day', columns: ['site_id', 'day'])]
#[ORM\Index(name: 'idx_analytics_daily_stats_day', columns: ['day'])]
class DailyStat
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'site_id', length: 36)]
    private string $siteId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column]
    private int $visitors = 0;

    #[ORM\Column(name: 'new_visitors')]
    private int $newVisitors = 0;

    #[ORM\Column]
    private int $sessions = 0;

    #[ORM\Column]
    private int $bounces = 0;

    #[ORM\Column]
    private int $pageviews = 0;

    #[ORM\Column]
    private int $events = 0;

    #[ORM\Column(name: 'total_duration_seconds')]
    private int $totalDurationSeconds = 0;

    private function __construct(string $siteId, \DateTimeImmutable $day)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->siteId = $siteId;
        $this->day = $day;
    }

    /** Open (or re-open) the rollup bucket of a day; the time of day is dropped. */
    public static function forDay(string $siteId, \DateTimeImmutable $day): self
    {
        return new self($siteId, $day->setTime(0, 0));
    }

    /**
     * Add one aggregation step to the bucket.
     *
     * @param int $totalDurationSeconds Sum of `analytics_sessions.duration_seconds` of the day.
     */
    public function add(
        int $visitors = 0,
        int $newVisitors = 0,
        int $sessions = 0,
        int $bounces = 0,
        int $pageviews = 0,
        int $events = 0,
        int $totalDurationSeconds = 0,
    ): void {
        $this->visitors += max(0, $visitors);
        $this->newVisitors += max(0, $newVisitors);
        $this->sessions += max(0, $sessions);
        $this->bounces += max(0, $bounces);
        $this->pageviews += max(0, $pageviews);
        $this->events += max(0, $events);
        $this->totalDurationSeconds += max(0, $totalDurationSeconds);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function siteId(): string
    {
        return $this->siteId;
    }

    public function day(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function visitors(): int
    {
        return $this->visitors;
    }

    public function newVisitors(): int
    {
        return $this->newVisitors;
    }

    public function sessions(): int
    {
        return $this->sessions;
    }

    public function bounces(): int
    {
        return $this->bounces;
    }

    public function pageviews(): int
    {
        return $this->pageviews;
    }

    public function events(): int
    {
        return $this->events;
    }

    public function totalDurationSeconds(): int
    {
        return $this->totalDurationSeconds;
    }
}

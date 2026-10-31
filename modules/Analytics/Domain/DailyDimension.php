<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Per-day dimension bucket (`analytics_daily_dimensions`): one row per
 * `(site, day, dimension, value)` combination, e.g. the top pages of a day.
 *
 * The dimension list is closed, see {@see Dimension}; values stay free-form strings.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_daily_dimensions')]
#[ORM\UniqueConstraint(
    name: 'uniq_analytics_daily_dimensions_bucket',
    columns: ['site_id', 'day', 'dimension', 'value'],
)]
class DailyDimension
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'site_id', length: 36)]
    private string $siteId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column(length: 20)]
    private string $dimension;

    #[ORM\Column(length: 255)]
    private string $value;

    #[ORM\Column]
    private int $visitors = 0;

    #[ORM\Column]
    private int $sessions = 0;

    #[ORM\Column]
    private int $pageviews = 0;

    #[ORM\Column]
    private int $events = 0;

    #[ORM\Column(name: 'duration_seconds')]
    private int $durationSeconds = 0;

    private function __construct(string $siteId, \DateTimeImmutable $day, string $dimension, string $value)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->siteId = $siteId;
        $this->day = $day;
        $this->dimension = $dimension;
        $this->value = $value;
    }

    /**
     * Open (or re-open) the rollup bucket of one dimension value.
     *
     * @param string $dimension One of {@see Dimension::ALL}.
     * @param string $value Bucket value (page path, referrer host, event name, …).
     *
     * @throws \InvalidArgumentException When the dimension is not part of the phase 1 set.
     */
    public static function forDay(
        string $siteId,
        \DateTimeImmutable $day,
        string $dimension,
        string $value,
    ): self {
        $dimension = trim($dimension);
        if (!Dimension::isKnown($dimension)) {
            throw new \InvalidArgumentException('analytics.error.invalid_dimension');
        }

        return new self($siteId, $day->setTime(0, 0), $dimension, self::clip($value, 255));
    }

    /**
     * Add one aggregation step to the bucket.
     *
     * @param int $durationSeconds Sum of `analytics_sessions.duration_seconds` of the bucket.
     */
    public function add(
        int $visitors = 0,
        int $sessions = 0,
        int $pageviews = 0,
        int $events = 0,
        int $durationSeconds = 0,
    ): void {
        $this->visitors += max(0, $visitors);
        $this->sessions += max(0, $sessions);
        $this->pageviews += max(0, $pageviews);
        $this->events += max(0, $events);
        $this->durationSeconds += max(0, $durationSeconds);
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

    public function dimension(): string
    {
        return $this->dimension;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function visitors(): int
    {
        return $this->visitors;
    }

    public function sessions(): int
    {
        return $this->sessions;
    }

    public function pageviews(): int
    {
        return $this->pageviews;
    }

    public function events(): int
    {
        return $this->events;
    }

    public function durationSeconds(): int
    {
        return $this->durationSeconds;
    }

    /** Keep a stored string within its column capacity so rollups cannot fail on long input. */
    private static function clip(string $value, int $max): string
    {
        $value = trim($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Repositories;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Analytics\Application\TrafficClassifier;

/**
 * Read-side aggregates for the admin overview, the report pages, the dashboard widgets and the API.
 *
 * Admin numbers are computed from the raw tables so they include the current day; excluded
 * (stored bot / internal) traffic is filtered with the props marker written by IngestService.
 * Day buckets are derived in PHP from `occurred_at` because `DATE()`/`CAST` is not portable
 * across the supported drivers (same approach as `Modules\Forum\Application\ForumInsights`).
 */
final class ReportRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Headline numbers for a site and day range.
     *
     * @param string $from `Y-m-d` (inclusive)
     * @param string $to `Y-m-d` (inclusive)
     * @return array{visitors: int, new_visitors: int, sessions: int, bounces: int, bounce_rate: float,
     *   pageviews: int, events: int, avg_duration_seconds: int, total_duration_seconds: int}
     */
    public function kpi(string $siteId, string $from, string $to): array
    {
        [$start, $end] = $this->bounds($from, $to);

        $pageviewTotals = $this->one(
            'SELECT COUNT(id) AS pageviews, COUNT(DISTINCT visitor_id) AS visitors FROM analytics_pageviews'
            . ' WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ? AND (props IS NULL OR props NOT LIKE ?)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        );
        $sessionTotals = $this->one(
            'SELECT COUNT(id) AS sessions, SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) AS bounces,'
            . ' COALESCE(SUM(duration_seconds), 0) AS duration FROM analytics_sessions'
            . ' WHERE site_id = ? AND started_at >= ? AND started_at < ? AND device_type NOT IN (?, ?)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        );
        $eventTotals = $this->one(
            'SELECT COUNT(id) AS events FROM analytics_events'
            . ' WHERE name <> \'session_replay\' AND site_id = ? AND occurred_at >= ? AND occurred_at < ? AND (props IS NULL OR props NOT LIKE ?)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        );
        $newVisitors = $this->one(
            'SELECT COUNT(id) AS new_visitors FROM analytics_visitors'
            . ' WHERE site_id = ? AND first_seen_at >= ? AND first_seen_at < ? AND device_type NOT IN (?, ?)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        );

        $sessions = (int) ($sessionTotals['sessions'] ?? 0);
        $bounces = (int) ($sessionTotals['bounces'] ?? 0);
        $duration = (int) ($sessionTotals['duration'] ?? 0);

        return [
            'visitors' => (int) ($pageviewTotals['visitors'] ?? 0),
            'new_visitors' => (int) ($newVisitors['new_visitors'] ?? 0),
            'sessions' => $sessions,
            'bounces' => $bounces,
            'bounce_rate' => $sessions > 0 ? round($bounces / $sessions, 4) : 0.0,
            'pageviews' => (int) ($pageviewTotals['pageviews'] ?? 0),
            'events' => (int) ($eventTotals['events'] ?? 0),
            'avg_duration_seconds' => $sessions > 0 ? (int) round($duration / $sessions) : 0,
            'total_duration_seconds' => $duration,
        ];
    }

    /**
     * Daily series with every day of the range present (zero filled).
     *
     * @return list<array{day: string, visitors: int, pageviews: int, events: int, sessions: int}>
     */
    public function trend(string $siteId, string $from, string $to): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $days = $this->dayRange($from, $to);

        $pageviews = $this->keyedByDay($this->all(
            'SELECT substr(occurred_at, 1, 10) AS day, COUNT(id) AS pageviews,'
            . ' COUNT(DISTINCT visitor_id) AS visitors FROM analytics_pageviews'
            . ' WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ? AND (props IS NULL OR props NOT LIKE ?)'
            . ' GROUP BY substr(occurred_at, 1, 10)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        ));
        $events = $this->keyedByDay($this->all(
            'SELECT substr(occurred_at, 1, 10) AS day, COUNT(id) AS events FROM analytics_events'
            . ' WHERE name <> \'session_replay\' AND site_id = ? AND occurred_at >= ? AND occurred_at < ? AND (props IS NULL OR props NOT LIKE ?)'
            . ' GROUP BY substr(occurred_at, 1, 10)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        ));
        $sessions = $this->keyedByDay($this->all(
            'SELECT substr(started_at, 1, 10) AS day, COUNT(id) AS sessions FROM analytics_sessions'
            . ' WHERE site_id = ? AND started_at >= ? AND started_at < ? AND device_type NOT IN (?, ?)'
            . ' GROUP BY substr(started_at, 1, 10)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        ));

        $out = [];
        foreach ($days as $day) {
            $out[] = [
                'day' => $day,
                'visitors' => (int) ($pageviews[$day]['visitors'] ?? 0),
                'pageviews' => (int) ($pageviews[$day]['pageviews'] ?? 0),
                'events' => (int) ($events[$day]['events'] ?? 0),
                'sessions' => (int) ($sessions[$day]['sessions'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{value: string, pageviews: int, visitors: int, engaged_seconds: int}>
     */
    public function topPaths(string $siteId, string $from, string $to, int $limit = 10): array
    {
        [$start, $end] = $this->bounds($from, $to);

        return array_map(static fn (array $row): array => [
            'value' => (string) ($row['bucket'] ?? ''),
            'pageviews' => (int) ($row['pageviews'] ?? 0),
            'visitors' => (int) ($row['visitors'] ?? 0),
            'engaged_seconds' => (int) ($row['engaged_seconds'] ?? 0),
        ], $this->all(
            'SELECT path AS bucket, COUNT(id) AS pageviews, COUNT(DISTINCT visitor_id) AS visitors,'
            . ' COALESCE(SUM(engaged_seconds), 0) AS engaged_seconds FROM analytics_pageviews'
            . ' WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ? AND (props IS NULL OR props NOT LIKE ?)'
            . ' GROUP BY path ORDER BY pageviews DESC LIMIT ' . max(1, $limit),
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        ));
    }

    /**
     * @return list<array{value: string, pageviews: int, visitors: int, engaged_seconds: int}>
     */
    public function topReferrers(string $siteId, string $from, string $to, int $limit = 10): array
    {
        [$start, $end] = $this->bounds($from, $to);

        return array_map(static fn (array $row): array => [
            'value' => (string) ($row['bucket'] ?? ''),
            'pageviews' => (int) ($row['pageviews'] ?? 0),
            'visitors' => (int) ($row['visitors'] ?? 0),
            'engaged_seconds' => (int) ($row['engaged_seconds'] ?? 0),
        ], $this->all(
            'SELECT referrer_host AS bucket, COUNT(id) AS pageviews, COUNT(DISTINCT visitor_id) AS visitors,'
            . ' COALESCE(SUM(engaged_seconds), 0) AS engaged_seconds FROM analytics_pageviews'
            . ' WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ? AND referrer_host IS NOT NULL'
            . ' AND (props IS NULL OR props NOT LIKE ?)'
            . ' GROUP BY referrer_host ORDER BY pageviews DESC LIMIT ' . max(1, $limit),
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        ));
    }

    /**
     * @return list<array{value: string, sessions: int, visitors: int, pageviews: int, events: int}>
     */
    public function topSources(string $siteId, string $from, string $to, int $limit = 10): array
    {
        return $this->sessionBuckets($siteId, $from, $to, 'source', $limit);
    }

    /**
     * @return list<array{value: string, sessions: int, visitors: int, pageviews: int, events: int}>
     */
    public function topDevices(string $siteId, string $from, string $to, int $limit = 10): array
    {
        return $this->sessionBuckets($siteId, $from, $to, 'device_type', $limit);
    }

    /**
     * @return list<array{value: string, sessions: int, visitors: int, pageviews: int, events: int}>
     */
    public function topBrowsers(string $siteId, string $from, string $to, int $limit = 10): array
    {
        return $this->sessionBuckets($siteId, $from, $to, 'browser', $limit);
    }

    /**
     * @return list<array{value: string, sessions: int, visitors: int, pageviews: int, events: int}>
     */
    public function topOperatingSystems(string $siteId, string $from, string $to, int $limit = 10): array
    {
        return $this->sessionBuckets($siteId, $from, $to, 'os', $limit);
    }

    /**
     * @return list<array{value: string, events: int, visitors: int}>
     */
    public function topEvents(string $siteId, string $from, string $to, int $limit = 10): array
    {
        [$start, $end] = $this->bounds($from, $to);

        return array_map(static fn (array $row): array => [
            'value' => (string) ($row['bucket'] ?? ''),
            'events' => (int) ($row['events'] ?? 0),
            'visitors' => (int) ($row['visitors'] ?? 0),
        ], $this->all(
            'SELECT name AS bucket, COUNT(id) AS events, COUNT(DISTINCT visitor_id) AS visitors'
            . ' FROM analytics_events WHERE name <> \'session_replay\' AND site_id = ? AND occurred_at >= ? AND occurred_at < ?'
            . ' AND (props IS NULL OR props NOT LIKE ?)'
            . ' GROUP BY name ORDER BY events DESC LIMIT ' . max(1, $limit),
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        ));
    }

    /**
     * Pseudonymous visitor rows for the raw visitors report (permission `analytics.raw`).
     *
     * @return list<array<string, mixed>>
     */
    public function visitors(string $siteId, string $from, string $to, int $limit = 100, int $offset = 0): array
    {
        [$start, $end] = $this->bounds($from, $to);

        return $this->all(
            'SELECT id, visitor_hash, first_seen_at, last_seen_at, session_count, pageview_count, event_count,'
            . ' device_type, browser, os, language, country_code, first_referrer_host, landing_path, last_path'
            . ' FROM analytics_visitors WHERE site_id = ? AND last_seen_at >= ? AND last_seen_at < ?'
            . ' AND device_type NOT IN (?, ?) ORDER BY last_seen_at DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        );
    }

    public function visitorCount(string $siteId, string $from, string $to): int
    {
        [$start, $end] = $this->bounds($from, $to);
        $row = $this->one(
            'SELECT COUNT(id) AS total FROM analytics_visitors'
            . ' WHERE site_id = ? AND last_seen_at >= ? AND last_seen_at < ? AND device_type NOT IN (?, ?)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return list<array{value: string, sessions: int, visitors: int, pageviews: int, events: int}>
     */
    private function sessionBuckets(string $siteId, string $from, string $to, string $column, int $limit): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $connection = $this->em->getConnection();
        $column = SqlIdentifier::quote($connection, $column);

        return array_map(static fn (array $row): array => [
            'value' => (string) ($row['bucket'] ?? ''),
            'sessions' => (int) ($row['sessions'] ?? 0),
            'visitors' => (int) ($row['visitors'] ?? 0),
            'pageviews' => (int) ($row['pageviews'] ?? 0),
            'events' => (int) ($row['events'] ?? 0),
        ], $this->all(
            'SELECT ' . $column . ' AS bucket, COUNT(id) AS sessions, COUNT(DISTINCT visitor_id) AS visitors,'
            . ' COALESCE(SUM(pageview_count), 0) AS pageviews, COALESCE(SUM(event_count), 0) AS events'
            . ' FROM analytics_sessions WHERE site_id = ? AND started_at >= ? AND started_at < ?'
            . ' AND device_type NOT IN (?, ?) GROUP BY ' . $column . ' ORDER BY sessions DESC LIMIT ' . max(1, $limit),
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        ));
    }

    /**
     * @return array{0: string, 1: string} `Y-m-d H:i:s` bounds, `$to` day included
     */
    private function bounds(string $from, string $to): array
    {
        $start = $this->day($from);
        $end = (new \DateTimeImmutable($this->day($to) . ' 00:00:00'))->modify('+1 day');

        return [$start . ' 00:00:00', $end->format('Y-m-d H:i:s')];
    }

    private function day(string $day): string
    {
        try {
            return (new \DateTimeImmutable(trim($day)))->format('Y-m-d');
        } catch (\Exception) {
            return (new \DateTimeImmutable('today'))->format('Y-m-d');
        }
    }

    /** @return list<string> */
    private function dayRange(string $from, string $to): array
    {
        $start = new \DateTimeImmutable($this->day($from) . ' 00:00:00');
        $end = new \DateTimeImmutable($this->day($to) . ' 00:00:00');
        $days = [];
        for ($cursor = $start; $cursor <= $end && count($days) < 400; $cursor = $cursor->modify('+1 day')) {
            $days[] = $cursor->format('Y-m-d');
        }

        return $days;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyedByDay(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $day = (string) ($row['day'] ?? '');
            if ($day !== '') {
                $out[$day] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>
     */
    private function one(string $sql, array $params): array
    {
        $row = $this->em->getConnection()->fetchAssociative($sql, $params);

        return is_array($row) ? $row : [];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, array $params): array
    {
        return $this->em->getConnection()->fetchAllAssociative($sql, $params);
    }
}

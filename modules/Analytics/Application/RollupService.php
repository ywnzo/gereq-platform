<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Analytics\Application\Repositories\RollupRepository;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Domain\VisitSession;
use Modules\Analytics\Domain\Visitor;

/**
 * Idempotent daily rollup into `analytics_daily_stats` + `analytics_daily_dimensions`.
 *
 * Dimension rows of the affected day are deleted and re-inserted, so re-running the hourly job
 * always converges to the same values. Bot / internal hits are excluded (they only exist when
 * `analytics.keep_bot_hits` is on) so reports never mix them with real traffic.
 */
final class RollupService
{
    /**
     * Upper bound of a single rebuild window.
     *
     * The queue payload is JSON reconstructed through the job constructor, so a forged or internal
     * payload must not be able to request an unbounded rebuild (rollups further back are rebuilt by
     * their own scheduled runs).
     */
    private const MAX_ROLLUP_DAYS = 400;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SiteRepository $sites,
        private readonly RollupRepository $rollups,
    ) {
    }

    /**
     * Roll up the given day (default: today) and the `$days - 1` days before it.
     *
     * @return array{rows: int} Number of day/site stat rows written.
     */
    public function rollup(?string $day = null, int $days = 1): array
    {
        $end = $this->parseDay($day) ?? new \DateTimeImmutable('today');
        $days = min(self::MAX_ROLLUP_DAYS, max(1, $days));
        $rows = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $current = $end->modify('-' . $offset . ' days')->format('Y-m-d');
            foreach ($this->sites->all() as $site) {
                $this->rollupSiteDay((string) $site['id'], $current);
                $rows++;
            }
        }

        return ['rows' => $rows];
    }

    /**
     * Recompute every aggregate + dimension for one site and one day.
     */
    public function rollupSiteDay(string $siteId, string $day): void
    {
        $start = $day . ' 00:00:00';
        $end = (new \DateTimeImmutable($day . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');

        $this->rollups->upsertStat($siteId, $day, $this->statCounts($siteId, $start, $end));
        $this->rollups->deleteDimensionRows($siteId, $day);

        foreach ($this->dimensionRows($siteId, $start, $end) as $dimension => $values) {
            foreach ($values as $value => $counts) {
                $this->rollups->insertDimension($siteId, $day, $dimension, (string) $value, $counts);
            }
        }
    }

    /**
     * @return array{visitors: int, new_visitors: int, sessions: int, bounces: int, pageviews: int,
     *   events: int, total_duration_seconds: int}
     */
    private function statCounts(string $siteId, string $start, string $end): array
    {
        $connection = $this->em->getConnection();

        $pageviews = (int) $connection->fetchOne(
            'SELECT COUNT(id) FROM analytics_pageviews WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?'
            . ' AND (props IS NULL OR props NOT LIKE ?)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        );
        $events = (int) $connection->fetchOne(
            'SELECT COUNT(id) FROM analytics_events WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?'
            . ' AND (props IS NULL OR props NOT LIKE ?)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        );
        $visitors = (int) $connection->fetchOne(
            'SELECT COUNT(DISTINCT visitor_id) FROM analytics_pageviews WHERE site_id = ? AND occurred_at >= ?'
            . ' AND occurred_at < ? AND (props IS NULL OR props NOT LIKE ?)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        );
        $newVisitors = (int) $connection->fetchOne(
            'SELECT COUNT(id) FROM analytics_visitors WHERE site_id = ? AND first_seen_at >= ?'
            . ' AND first_seen_at < ? AND device_type NOT IN (?, ?)',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        );
        $sessionRows = $this->em->createQueryBuilder()
            ->select(
                'COUNT(s.id) AS sessions',
                'SUM(CASE WHEN s.isBounce = true THEN 1 ELSE 0 END) AS bounces',
                'COALESCE(SUM(s.durationSeconds), 0) AS duration'
            )
            ->from(VisitSession::class, 's')
            ->where('s.siteId = :site')
            ->andWhere('s.startedAt >= :start')
            ->andWhere('s.startedAt < :end')
            ->andWhere('s.deviceType NOT IN (:excludedDevices)')
            ->setParameter('site', $siteId)
            ->setParameter('start', new \DateTimeImmutable($start))
            ->setParameter('end', new \DateTimeImmutable($end))
            ->setParameter('excludedDevices', TrafficClassifier::EXCLUDED_DEVICES)
            ->getQuery()
            ->getArrayResult();

        $session = is_array($sessionRows[0] ?? null) ? $sessionRows[0] : [];

        return [
            'visitors' => $visitors,
            'new_visitors' => $newVisitors,
            'sessions' => (int) ($session['sessions'] ?? 0),
            'bounces' => (int) ($session['bounces'] ?? 0),
            'pageviews' => $pageviews,
            'events' => $events,
            'total_duration_seconds' => (int) ($session['duration'] ?? 0),
        ];
    }

    /**
     * @return array<string, array<string, array{visitors: int, sessions: int, pageviews: int,
     *   events: int, duration_seconds: int}>>
     */
    private function dimensionRows(string $siteId, string $start, string $end): array
    {
        return [
            'path' => $this->pageviewDimension($siteId, $start, $end, 'path'),
            'referrer' => $this->pageviewDimension($siteId, $start, $end, 'referrer_host', false, 'direct'),
            'entry' => $this->pageviewDimension($siteId, $start, $end, 'path', true),
            'event' => $this->eventDimension($siteId, $start, $end),
            'device' => $this->sessionDimension($siteId, $start, $end, 'device_type'),
            'browser' => $this->sessionDimension($siteId, $start, $end, 'browser'),
            'os' => $this->sessionDimension($siteId, $start, $end, 'os'),
            'exit' => $this->exitDimension($siteId, $start, $end),
            'source' => $this->sourceDimension($siteId, $start, $end),
            'country' => $this->countryDimension($siteId, $start, $end),
        ];
    }

    /**
     * @return array<string, array{visitors: int, sessions: int, pageviews: int, events: int, duration_seconds: int}>
     */
    private function pageviewDimension(
        string $siteId,
        string $start,
        string $end,
        string $column,
        bool $onlyEntries = false,
        string $fallback = '',
    ): array {
        $sql = 'SELECT ' . $column . ' AS bucket, COUNT(id) AS pageviews,'
            . ' COUNT(DISTINCT visitor_id) AS visitors, COUNT(DISTINCT session_id) AS sessions,'
            . ' COALESCE(SUM(engaged_seconds), 0) AS duration'
            . ' FROM analytics_pageviews WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?'
            . ' AND (props IS NULL OR props NOT LIKE ?)';
        if ($onlyEntries) {
            $sql .= ' AND is_entry = 1';
        }
        $sql .= ' GROUP BY ' . $column;

        return $this->collectBuckets($this->em->getConnection()->fetchAllAssociative(
            $sql,
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        ), $fallback);
    }

    /**
     * @return array<string, array{visitors: int, sessions: int, pageviews: int, events: int, duration_seconds: int}>
     */
    private function eventDimension(string $siteId, string $start, string $end): array
    {
        return $this->collectBuckets($this->em->getConnection()->fetchAllAssociative(
            'SELECT name AS bucket, COUNT(id) AS events, COUNT(DISTINCT visitor_id) AS visitors,'
            . ' COUNT(DISTINCT session_id) AS sessions FROM analytics_events'
            . ' WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ? AND (props IS NULL OR props NOT LIKE ?)'
            . ' GROUP BY name',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        ));
    }

    /**
     * @return array<string, array{visitors: int, sessions: int, pageviews: int, events: int, duration_seconds: int}>
     */
    private function sessionDimension(string $siteId, string $start, string $end, string $column): array
    {
        return $this->collectBuckets($this->em->getConnection()->fetchAllAssociative(
            'SELECT ' . $column . ' AS bucket, COUNT(id) AS sessions, COUNT(DISTINCT visitor_id) AS visitors,'
            . ' COALESCE(SUM(pageview_count), 0) AS pageviews, COALESCE(SUM(event_count), 0) AS events,'
            . ' COALESCE(SUM(duration_seconds), 0) AS duration FROM analytics_sessions'
            . ' WHERE site_id = ? AND started_at >= ? AND started_at < ? AND device_type NOT IN (?, ?)'
            . ' GROUP BY ' . $column,
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        ));
    }

    /**
     * @return array<string, array{visitors: int, sessions: int, pageviews: int, events: int, duration_seconds: int}>
     */
    private function exitDimension(string $siteId, string $start, string $end): array
    {
        return $this->collectBuckets($this->em->getConnection()->fetchAllAssociative(
            'SELECT exit_path AS bucket, COUNT(id) AS sessions, COUNT(DISTINCT visitor_id) AS visitors,'
            . ' COALESCE(SUM(pageview_count), 0) AS pageviews, COALESCE(SUM(event_count), 0) AS events,'
            . ' COALESCE(SUM(duration_seconds), 0) AS duration FROM analytics_sessions'
            . ' WHERE site_id = ? AND started_at >= ? AND started_at < ? AND device_type NOT IN (?, ?)'
            . ' AND exit_path IS NOT NULL GROUP BY exit_path',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        ));
    }

    /**
     * @return array<string, array{visitors: int, sessions: int, pageviews: int, events: int, duration_seconds: int}>
     */
    private function sourceDimension(string $siteId, string $start, string $end): array
    {
        return $this->collectBuckets($this->em->getConnection()->fetchAllAssociative(
            'SELECT source AS bucket, COUNT(id) AS sessions, COUNT(DISTINCT visitor_id) AS visitors,'
            . ' COALESCE(SUM(pageview_count), 0) AS pageviews, COALESCE(SUM(event_count), 0) AS events,'
            . ' COALESCE(SUM(duration_seconds), 0) AS duration FROM analytics_sessions'
            . ' WHERE site_id = ? AND started_at >= ? AND started_at < ? AND device_type NOT IN (?, ?)'
            . ' GROUP BY source',
            [$siteId, $start, $end, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        ));
    }

    /**
     * Country is only ever filled from an inbound header (never resolved); visitors without the
     * header land in the `unknown` bucket, see {@see \Modules\Analytics\Domain\Dimension::COUNTRY}.
     *
     * @return array<string, array{visitors: int, sessions: int, pageviews: int, events: int, duration_seconds: int}>
     */
    private function countryDimension(string $siteId, string $start, string $end): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select(
                'v.countryCode AS bucket, COUNT(v.id) AS visitors',
                'COALESCE(SUM(v.sessionCount), 0) AS sessions',
                'COALESCE(SUM(v.pageviewCount), 0) AS pageviews',
                'COALESCE(SUM(v.eventCount), 0) AS events'
            )
            ->from(Visitor::class, 'v')
            ->where('v.siteId = :site')
            ->andWhere('v.lastSeenAt >= :start')
            ->andWhere('v.lastSeenAt < :end')
            ->andWhere('v.deviceType NOT IN (:excludedDevices)')
            ->setParameter('site', $siteId)
            ->setParameter('start', new \DateTimeImmutable($start))
            ->setParameter('end', new \DateTimeImmutable($end))
            ->setParameter('excludedDevices', TrafficClassifier::EXCLUDED_DEVICES)
            ->groupBy('v.countryCode')
            ->getQuery()
            ->getArrayResult();

        return $this->collectBuckets($rows, 'unknown');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{visitors: int, sessions: int, pageviews: int, events: int, duration_seconds: int}>
     */
    private function collectBuckets(array $rows, string $fallback = ''): array
    {
        $out = [];
        foreach ($rows as $row) {
            $bucket = $row['bucket'] ?? null;
            $value = is_scalar($bucket) ? trim((string) $bucket) : '';
            if ($value === '') {
                $value = $fallback;
            }
            if ($value === '') {
                continue;
            }
            $out[$value] = [
                'visitors' => (int) ($row['visitors'] ?? 0),
                'sessions' => (int) ($row['sessions'] ?? 0),
                'pageviews' => (int) ($row['pageviews'] ?? 0),
                'events' => (int) ($row['events'] ?? 0),
                'duration_seconds' => (int) ($row['duration'] ?? 0),
            ];
        }

        return $out;
    }

    private function parseDay(?string $day): ?\DateTimeImmutable
    {
        if ($day === null || trim($day) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($day)))->setTime(0, 0);
        } catch (\Exception) {
            return null;
        }
    }
}

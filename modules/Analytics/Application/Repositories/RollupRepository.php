<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Repositories;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Analytics\Domain\DailyDimension;
use Modules\Analytics\Domain\DailyStat;
use Ramsey\Uuid\Uuid;

/**
 * Data access for the daily rollup tables.
 *
 * Rollups are the long-lived aggregate: they outlive the raw pageviews/events retention window
 * and back the read-only API. `analytics_daily_dimensions` rows for a day are deleted and
 * re-inserted, so `rollup()` is idempotent by construction.
 */
final class RollupRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array{visitors: int, new_visitors: int, sessions: int, bounces: int, pageviews: int,
     *   events: int, total_duration_seconds: int} $counts
     */
    public function upsertStat(string $siteId, string $day, array $counts): void
    {
        $existing = $this->findStat($siteId, $day);
        $params = [
            $counts['visitors'],
            $counts['new_visitors'],
            $counts['sessions'],
            $counts['bounces'],
            $counts['pageviews'],
            $counts['events'],
            $counts['total_duration_seconds'],
        ];

        if ($existing !== null) {
            $params[] = (string) $existing['id'];
            $this->em->getConnection()->executeStatement(
                'UPDATE analytics_daily_stats SET visitors = ?, new_visitors = ?, sessions = ?, bounces = ?,'
                . ' pageviews = ?, events = ?, total_duration_seconds = ? WHERE id = ?',
                $params,
            );

            return;
        }

        $this->em->getConnection()->executeStatement(
            'INSERT INTO analytics_daily_stats (id, site_id, day, visitors, new_visitors, sessions, bounces,'
            . ' pageviews, events, total_duration_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::uuid7()->toString(), $siteId, $day, ...$params],
        );
    }

    /** @return array<string, mixed>|null */
    public function findStat(string $siteId, string $day): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->em->createQueryBuilder()
            ->select(
                'd.id AS id',
                'd.day AS day',
                'd.visitors AS visitors',
                'd.newVisitors AS new_visitors',
                'd.sessions AS sessions',
                'd.bounces AS bounces',
                'd.pageviews AS pageviews',
                'd.events AS events',
                'd.totalDurationSeconds AS total_duration_seconds',
            )
            ->from(DailyStat::class, 'd')
            ->where('d.siteId = :site')
            ->andWhere('d.day = :day')
            ->setParameter('site', $siteId)
            ->setParameter('day', $day)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row !== null ? array_merge($row, ['day' => self::dayOf($row['day'] ?? $day)]) : null;
    }

    public function deleteDimensionRows(string $siteId, string $day): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'DELETE FROM analytics_daily_dimensions WHERE site_id = ? AND day = ?',
            [$siteId, $day],
        );
    }

    /**
     * @param array{visitors: int, sessions: int, pageviews: int, events: int, duration_seconds: int} $counts
     */
    public function insertDimension(
        string $siteId,
        string $day,
        string $dimension,
        string $value,
        array $counts,
    ): void {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO analytics_daily_dimensions (id, site_id, day, dimension, value, visitors, sessions,'
            . ' pageviews, events, duration_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Uuid::uuid7()->toString(),
                $siteId,
                $day,
                $dimension,
                mb_substr($value, 0, 190),
                $counts['visitors'],
                $counts['sessions'],
                $counts['pageviews'],
                $counts['events'],
                $counts['duration_seconds'],
            ],
        );
    }

    /**
     * Aggregated daily stats for one or more sites.
     *
     * @param list<string> $siteIds
     * @return list<array{day: string, visitors: int, new_visitors: int, sessions: int, bounces: int,
     *   pageviews: int, events: int, total_duration_seconds: int}>
     */
    public function statsRange(array $siteIds, string $from, string $to): array
    {
        if ($siteIds === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select(
                'd.day AS day',
                'SUM(d.visitors) AS visitors',
                'SUM(d.newVisitors) AS new_visitors',
                'SUM(d.sessions) AS sessions',
                'SUM(d.bounces) AS bounces',
                'SUM(d.pageviews) AS pageviews',
                'SUM(d.events) AS events',
                'SUM(d.totalDurationSeconds) AS total_duration_seconds',
            )
            ->from(DailyStat::class, 'd')
            ->where('d.siteId IN (:sites)')
            ->andWhere('d.day >= :from')
            ->andWhere('d.day <= :to')
            ->setParameter('sites', $siteIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('d.day')
            ->orderBy('d.day', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'day' => self::dayOf($row['day'] ?? ''),
            'visitors' => (int) ($row['visitors'] ?? 0),
            'new_visitors' => (int) ($row['new_visitors'] ?? 0),
            'sessions' => (int) ($row['sessions'] ?? 0),
            'bounces' => (int) ($row['bounces'] ?? 0),
            'pageviews' => (int) ($row['pageviews'] ?? 0),
            'events' => (int) ($row['events'] ?? 0),
            'total_duration_seconds' => (int) ($row['total_duration_seconds'] ?? 0),
        ], $rows);
    }

    /**
     * Top values of one dimension across a day range.
     *
     * @param list<string> $siteIds
     * @return list<array{value: string, visitors: int, sessions: int, pageviews: int, events: int,
     *   duration_seconds: int}>
     */
    public function dimensionTop(array $siteIds, string $dimension, string $from, string $to, int $limit = 20): array
    {
        if ($siteIds === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select(
                'd.value AS value',
                'SUM(d.visitors) AS visitors',
                'SUM(d.sessions) AS sessions',
                'SUM(d.pageviews) AS pageviews',
                'SUM(d.events) AS events',
                'SUM(d.durationSeconds) AS duration_seconds',
            )
            ->from(DailyDimension::class, 'd')
            ->where('d.siteId IN (:sites)')
            ->andWhere('d.dimension = :dimension')
            ->andWhere('d.day >= :from')
            ->andWhere('d.day <= :to')
            ->setParameter('sites', $siteIds)
            ->setParameter('dimension', $dimension)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('d.value')
            ->orderBy('pageviews', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'value' => (string) ($row['value'] ?? ''),
            'visitors' => (int) ($row['visitors'] ?? 0),
            'sessions' => (int) ($row['sessions'] ?? 0),
            'pageviews' => (int) ($row['pageviews'] ?? 0),
            'events' => (int) ($row['events'] ?? 0),
            'duration_seconds' => (int) ($row['duration_seconds'] ?? 0),
        ], $rows);
    }

    private static function dayOf(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}

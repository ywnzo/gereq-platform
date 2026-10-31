<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Repositories;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Analytics\Domain\Pageview;
use Modules\Analytics\Domain\TrackedEvent;
use Modules\Analytics\Domain\VisitSession;
use Modules\Analytics\Domain\Visitor;
use Ramsey\Uuid\Uuid;

/**
 * Data access for the raw traffic tables: `analytics_visitors`, `analytics_sessions`,
 * `analytics_pageviews`, `analytics_events` and `analytics_consent_events`.
 *
 * The write side is explicit SQL so the whole ingest fits into a single transaction and never
 * touches an ORM identity map. Reads are cheap DQL partial selects without associations.
 * All row keys are the database column names from the README.
 */
final class TrafficRepository
{
    /** Columns carrying JSON payloads. @var list<string> */
    private const JSON_COLUMNS = ['utm', 'props', 'categories'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    // --------------------------------------------------------------------- visitors

    /**
     * @return array{id: string, first_seen_at: ?string, session_count: int, pageview_count: int,
     *   event_count: int, is_bot: bool}|null
     */
    public function findVisitor(string $siteId, string $visitorHash): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->em->createQueryBuilder()
            ->select(
                'v.id AS id',
                'v.firstSeenAt AS first_seen_at',
                'v.sessionCount AS session_count',
                'v.pageviewCount AS pageview_count',
                'v.eventCount AS event_count',
                'v.deviceType AS device_type',
            )
            ->from(Visitor::class, 'v')
            ->where('v.siteId = :site')
            ->andWhere('v.visitorHash = :hash')
            ->setParameter('site', $siteId)
            ->setParameter('hash', $visitorHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'first_seen_at' => self::toDateString($row['first_seen_at'] ?? null),
            'session_count' => (int) ($row['session_count'] ?? 0),
            'pageview_count' => (int) ($row['pageview_count'] ?? 0),
            'event_count' => (int) ($row['event_count'] ?? 0),
            'is_bot' => (string) ($row['device_type'] ?? '') === 'bot',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insertVisitor(array $row): string
    {
        $row['id'] ??= Uuid::uuid7()->toString();
        $this->insert('analytics_visitors', [
            'id' => $row['id'],
            'site_id' => $row['site_id'] ?? '',
            'visitor_hash' => $row['visitor_hash'] ?? '',
            'hash_version' => (int) ($row['hash_version'] ?? 1),
            'first_seen_at' => $row['first_seen_at'] ?? null,
            'last_seen_at' => $row['last_seen_at'] ?? null,
            'session_count' => (int) ($row['session_count'] ?? 0),
            'pageview_count' => (int) ($row['pageview_count'] ?? 0),
            'event_count' => (int) ($row['event_count'] ?? 0),
            'first_referrer_host' => $row['first_referrer_host'] ?? null,
            'landing_path' => $row['landing_path'] ?? null,
            'last_path' => $row['last_path'] ?? null,
            'device_type' => $row['device_type'] ?? 'unknown',
            'browser' => $row['browser'] ?? 'unknown',
            'os' => $row['os'] ?? 'unknown',
            'language' => $row['language'] ?? null,
            'country_code' => $row['country_code'] ?? null,
        ]);

        return (string) $row['id'];
    }

    /**
     * Applies the ingest counter deltas and activity timestamps to one visitor.
     */
    public function touchVisitor(
        string $visitorId,
        \DateTimeImmutable $at,
        ?string $lastPath,
        int $sessionDelta = 0,
        int $pageviewDelta = 0,
        int $eventDelta = 0,
    ): void {
        $this->em->getConnection()->executeStatement(
            'UPDATE analytics_visitors SET last_seen_at = ?, last_path = COALESCE(?, last_path),'
            . ' session_count = session_count + ?, pageview_count = pageview_count + ?,'
            . ' event_count = event_count + ? WHERE id = ?',
            [
                self::format($at),
                $lastPath,
                $sessionDelta,
                $pageviewDelta,
                $eventDelta,
                $visitorId,
            ],
        );
    }

    /** @return list<string> Visitor ids stored for a hash (a hash may repeat after retention). */
    public function visitorIdsByHash(string $siteId, string $visitorHash): array
    {
        /** @var list<mixed> $ids */
        $ids = $this->em->createQueryBuilder()
            ->select('v.id')
            ->from(Visitor::class, 'v')
            ->where('v.siteId = :site')
            ->andWhere('v.visitorHash = :hash')
            ->setParameter('site', $siteId)
            ->setParameter('hash', $visitorHash)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }

    /**
     * Hard delete of every row belonging to a visitor hash (consent revocation).
     *
     * @return array{pageviews: int, events: int, sessions: int, visitors: int}
     */
    public function deleteVisitorData(string $siteId, string $visitorHash): array
    {
        $visitorIds = $this->visitorIdsByHash($siteId, $visitorHash);
        $connection = $this->em->getConnection();
        $deleted = ['pageviews' => 0, 'events' => 0, 'sessions' => 0, 'visitors' => 0];

        foreach ($visitorIds as $visitorId) {
            foreach ([
                'analytics_events' => 'events',
                'analytics_pageviews' => 'pageviews',
                'analytics_sessions' => 'sessions',
            ] as $table => $key) {
                $deleted[$key] += (int) $connection->executeStatement(
                    'DELETE FROM ' . SqlIdentifier::quote($connection, $table)
                    . ' WHERE site_id = ? AND visitor_id = ?',
                    [$siteId, $visitorId],
                );
            }
            // `analytics_visitors` keys the visit on its own id, not on a visitor_id column.
            $deleted['visitors'] += (int) $connection->executeStatement(
                'DELETE FROM analytics_visitors WHERE site_id = ? AND id = ?',
                [$siteId, $visitorId],
            );
        }

        return $deleted;
    }

    // --------------------------------------------------------------------- sessions

    /**
     * @return array{id: string, started_at: ?string, last_activity_at: ?string, pageview_count: int,
     *   event_count: int, entry_path: ?string, entry_referrer_host: ?string, source: string}|null
     */
    public function findOpenSession(string $siteId, string $visitorId): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->em->createQueryBuilder()
            ->select(
                's.id AS id',
                's.startedAt AS started_at',
                's.lastActivityAt AS last_activity_at',
                's.pageviewCount AS pageview_count',
                's.eventCount AS event_count',
                's.entryPath AS entry_path',
                's.entryReferrerHost AS entry_referrer_host',
                's.source AS source',
            )
            ->from(VisitSession::class, 's')
            ->where('s.siteId = :site')
            ->andWhere('s.visitorId = :visitor')
            ->andWhere('s.endedAt IS NULL')
            ->setParameter('site', $siteId)
            ->setParameter('visitor', $visitorId)
            ->orderBy('s.lastActivityAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'started_at' => self::toDateString($row['started_at'] ?? null),
            'last_activity_at' => self::toDateString($row['last_activity_at'] ?? null),
            'pageview_count' => (int) ($row['pageview_count'] ?? 0),
            'event_count' => (int) ($row['event_count'] ?? 0),
            'entry_path' => isset($row['entry_path']) ? (string) $row['entry_path'] : null,
            'entry_referrer_host' => isset($row['entry_referrer_host']) ? (string) $row['entry_referrer_host'] : null,
            'source' => (string) ($row['source'] ?? 'direct'),
        ];
    }

    /** @param array<string, mixed> $row */
    public function insertSession(array $row): string
    {
        $row['id'] ??= Uuid::uuid7()->toString();
        $this->insert('analytics_sessions', [
            'id' => $row['id'],
            'site_id' => $row['site_id'] ?? '',
            'visitor_id' => $row['visitor_id'] ?? '',
            'started_at' => $row['started_at'] ?? null,
            'last_activity_at' => $row['last_activity_at'] ?? null,
            'ended_at' => $row['ended_at'] ?? null,
            'pageview_count' => (int) ($row['pageview_count'] ?? 0),
            'event_count' => (int) ($row['event_count'] ?? 0),
            'duration_seconds' => (int) ($row['duration_seconds'] ?? 0),
            'is_bounce' => $row['is_bounce'] ?? true,
            'entry_path' => $row['entry_path'] ?? '/',
            'exit_path' => $row['exit_path'] ?? null,
            'entry_referrer_host' => $row['entry_referrer_host'] ?? null,
            'source' => $row['source'] ?? 'direct',
            'utm' => $row['utm'] ?? [],
            'device_type' => $row['device_type'] ?? 'unknown',
            'browser' => $row['browser'] ?? 'unknown',
            'os' => $row['os'] ?? 'unknown',
        ]);

        return (string) $row['id'];
    }

    public function touchSession(
        string $sessionId,
        \DateTimeImmutable $at,
        ?string $exitPath,
        int $pageviewDelta,
        int $eventDelta,
        int $durationSeconds,
        bool $isBounce,
    ): void {
        $this->em->getConnection()->executeStatement(
            'UPDATE analytics_sessions SET last_activity_at = ?, exit_path = COALESCE(?, exit_path),'
            . ' pageview_count = pageview_count + ?, event_count = event_count + ?,'
            . ' duration_seconds = ?, is_bounce = ? WHERE id = ?',
            [
                self::format($at),
                $exitPath,
                $pageviewDelta,
                $eventDelta,
                max(0, $durationSeconds),
                $isBounce ? 1 : 0,
                $sessionId,
            ],
        );
    }

    /** Closes every open session of a visitor (retention, revocation, idle sweeps). */
    public function closeOpenSessions(string $siteId, string $visitorId, \DateTimeImmutable $at): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'UPDATE analytics_sessions SET ended_at = ? WHERE site_id = ? AND visitor_id = ? AND ended_at IS NULL',
            [self::format($at), $siteId, $visitorId],
        );
    }

    // -------------------------------------------------------------------- pageviews

    /** @param array<string, mixed> $row */
    public function insertPageview(array $row): string
    {
        $row['id'] ??= Uuid::uuid7()->toString();
        $this->insert('analytics_pageviews', [
            'id' => $row['id'],
            'site_id' => $row['site_id'] ?? '',
            'session_id' => $row['session_id'] ?? '',
            'visitor_id' => $row['visitor_id'] ?? '',
            'occurred_at' => $row['occurred_at'] ?? null,
            'path' => $row['path'] ?? '/',
            'title' => $row['title'] ?? null,
            'referrer_host' => $row['referrer_host'] ?? null,
            'is_entry' => $row['is_entry'] ?? false,
            'is_exit' => $row['is_exit'] ?? true,
            'engaged_seconds' => (int) ($row['engaged_seconds'] ?? 0),
            'viewport_width' => $row['viewport_width'] ?? null,
            'viewport_height' => $row['viewport_height'] ?? null,
            'language' => $row['language'] ?? null,
            'props' => $row['props'] ?? [],
        ]);

        return (string) $row['id'];
    }

    /** A newer pageview in the same session means the previous one is no longer the exit page. */
    public function clearSessionExits(string $siteId, string $sessionId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE analytics_pageviews SET is_exit = ? WHERE site_id = ? AND session_id = ? AND is_exit = ?',
            [0, $siteId, $sessionId, 1],
        );
    }

    /**
     * Newest pageview of a session, used to attach engagement / click batches that arrive
     * in their own beacon without a `pageview` event.
     */
    public function findLatestPageviewId(string $siteId, string $sessionId): ?string
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->em->createQueryBuilder()
            ->select('p.id AS id')
            ->from(Pageview::class, 'p')
            ->where('p.siteId = :site')
            ->andWhere('p.sessionId = :session')
            ->setParameter('site', $siteId)
            ->setParameter('session', $sessionId)
            ->orderBy('p.occurredAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row !== null && isset($row['id']) ? (string) $row['id'] : null;
    }

    /** Add measured engaged time to an already stored pageview. */
    public function addPageviewEngagement(string $pageviewId, int $engagedSeconds): void
    {
        if ($engagedSeconds <= 0) {
            return;
        }

        $this->em->getConnection()->executeStatement(
            'UPDATE analytics_pageviews SET engaged_seconds = engaged_seconds + ? WHERE id = ?',
            [$engagedSeconds, $pageviewId],
        );
    }

    // ----------------------------------------------------------------------- events

    /** @param array<string, mixed> $row */
    public function insertEvent(array $row): string
    {
        $row = $this->eventRow($row);
        $this->insert('analytics_events', $row);

        return (string) $row['id'];
    }

    /** @param list<array<string, mixed>> $rows */
    public function insertEvents(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $connection = $this->em->getConnection();
        // Ten columns per row: stay below SQLite's conservative 999-bind limit.
        foreach (array_chunk($rows, 90) as $chunk) {
            $columns = [];
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $row = $this->eventRow($row);
                if ($columns === []) {
                    $columns = array_map(static fn (string $name): string => SqlIdentifier::quote($connection, $name), array_keys($row));
                }
                $values[] = '(' . implode(', ', array_fill(0, count($row), '?')) . ')';
                foreach ($row as $column => $value) {
                    $params[] = $this->bindValue($column, $value);
                }
            }
            $connection->executeStatement(
                'INSERT INTO ' . SqlIdentifier::quote($connection, 'analytics_events')
                . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $values),
                $params,
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function eventRow(array $row): array
    {
        return [
            'id' => $row['id'] ?? Uuid::uuid7()->toString(),
            'site_id' => $row['site_id'] ?? '',
            'session_id' => $row['session_id'] ?? '',
            'visitor_id' => $row['visitor_id'] ?? '',
            'pageview_id' => $row['pageview_id'] ?? null,
            'name' => $row['name'] ?? 'event',
            'occurred_at' => $row['occurred_at'] ?? null,
            'value' => $row['value'] ?? null,
            'props' => $row['props'] ?? [],
        ];
    }

    // --------------------------------------------------------------- consent events

    /** @param array<string, mixed> $row */
    public function insertConsentEvent(array $row): string
    {
        $row['id'] ??= Uuid::uuid7()->toString();
        $this->insert('analytics_consent_events', [
            'id' => $row['id'],
            'site_id' => $row['site_id'] ?? '',
            'occurred_at' => $row['occurred_at'] ?? null,
            'decision' => $row['decision'] ?? 'denied',
            'categories' => $row['categories'] ?? [],
            'visitor_hash' => $row['visitor_hash'] ?? null,
        ]);

        return (string) $row['id'];
    }

    // ----------------------------------------------------------------------- plumbing

    /**
     * @param array<string, mixed> $row
     */
    private function insert(string $table, array $row): void
    {
        $connection = $this->em->getConnection();
        $columns = [];
        $placeholders = [];
        $params = [];

        foreach ($row as $column => $value) {
            $columns[] = SqlIdentifier::quote($connection, $column);
            $placeholders[] = '?';
            $params[] = $this->bindValue($column, $value);
        }

        $connection->executeStatement(
            'INSERT INTO ' . SqlIdentifier::quote($connection, $table)
            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $params,
        );
    }

    private function bindValue(string $column, mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return self::format($value);
        }
        if (in_array($column, self::JSON_COLUMNS, true)) {
            return self::encodeJson(is_array($value) ? $value : []);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function format(\DateTimeInterface $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }

    private static function toDateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}

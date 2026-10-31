<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Repositories;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Chunked retention deletes driven by the indexed timestamp columns.
 *
 * Identifiers never come from request data — every target is a fixed table/column pair listed
 * below (quoted through {@see SqlIdentifier} before it reaches a statement), and rows are removed
 * in bounded id batches so a purge never holds a long transaction.
 */
final class PurgeRepository
{
    public const TARGET_PAGEVIEWS = 'pageviews';

    public const TARGET_EVENTS = 'events';

    public const TARGET_SESSIONS = 'sessions';

    public const TARGET_VISITORS = 'visitors';

    public const TARGET_CONSENT = 'consent';

    public const TARGET_STATS = 'stats';

    public const TARGET_DIMENSIONS = 'dimensions';

    /** Max ids per DELETE statement (SQLite keeps prepared-statement variables well above this). */
    private const ID_BATCH = 400;

    /**
     * target => [table, indexed cutoff column].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const TARGETS = [
        self::TARGET_PAGEVIEWS => ['analytics_pageviews', 'occurred_at'],
        self::TARGET_EVENTS => ['analytics_events', 'occurred_at'],
        self::TARGET_SESSIONS => ['analytics_sessions', 'last_activity_at'],
        self::TARGET_VISITORS => ['analytics_visitors', 'last_seen_at'],
        self::TARGET_CONSENT => ['analytics_consent_events', 'occurred_at'],
        self::TARGET_STATS => ['analytics_daily_stats', 'day'],
        self::TARGET_DIMENSIONS => ['analytics_daily_dimensions', 'day'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Rows older than the cutoff that a purge would remove. */
    public function countCandidates(string $target, string $cutoff): int
    {
        [$table, $column] = $this->resolve($target);
        $connection = $this->em->getConnection();

        return (int) $connection->fetchOne(
            'SELECT COUNT(id) FROM ' . SqlIdentifier::quote($connection, $table)
            . ' WHERE ' . SqlIdentifier::quote($connection, $column) . ' < ?',
            [$cutoff],
        );
    }

    /** @return list<string> */
    private function candidateIds(string $target, string $cutoff, int $limit): array
    {
        [$table, $column] = $this->resolve($target);
        $connection = $this->em->getConnection();

        /** @var list<mixed> $ids */
        $ids = $connection->fetchFirstColumn(
            'SELECT id FROM ' . SqlIdentifier::quote($connection, $table)
            . ' WHERE ' . SqlIdentifier::quote($connection, $column) . ' < ? LIMIT ' . max(1, $limit),
            [$cutoff],
        );

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }

    /**
     * Delete every row older than the cutoff in id batches; returns the number of removed rows.
     */
    public function purgeTable(string $target, string $cutoff, int $batchSize): int
    {
        [$table] = $this->resolve($target);
        $connection = $this->em->getConnection();
        $batchSize = max(1, $batchSize);
        $deleted = 0;

        while (true) {
            $ids = $this->candidateIds($target, $cutoff, $batchSize);
            if ($ids === []) {
                break;
            }

            $before = $deleted;
            foreach (array_chunk($ids, self::ID_BATCH) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
                $deleted += (int) $connection->executeStatement(
                    'DELETE FROM ' . SqlIdentifier::quote($connection, $table)
                    . ' WHERE ' . SqlIdentifier::quote($connection, 'id') . ' IN (' . $placeholders . ')',
                    $chunk,
                );
            }

            if ($deleted === $before) {
                // Nothing matched (concurrent delete) — stop instead of spinning.
                break;
            }
            if (count($ids) < $batchSize) {
                break;
            }
        }

        return $deleted;
    }

    /** @return array{0: string, 1: string} */
    private function resolve(string $target): array
    {
        if (!isset(self::TARGETS[$target])) {
            throw new \InvalidArgumentException('Unknown analytics purge target: ' . $target);
        }

        return self::TARGETS[$target];
    }
}

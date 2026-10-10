<?php

declare(strict_types=1);

namespace Gereq\Queue\Application;

use Gereq\Cache\AppCache;
use Gereq\Queue\FailedJob;
use Gereq\Queue\FailedJobStore;
use Gereq\Queue\Queue;

/**
 * Collects queue health for the dashboard, the sidebar badge and the admin page.
 *
 * The decision itself lives in QueueHealthStatus::evaluate() as a pure function; this class only
 * gathers the numbers. Follows the Vitality pattern: cached, and never throws — a broken Redis
 * must not take the admin down with it.
 */
final class QueueHealthChecker
{
    /** Seconds a snapshot stays cached. */
    public const CACHE_TTL = 30;

    private const CACHE_KEY = 'queues.health.status';

    public function __construct(
        private readonly Queue $queue,
        private readonly FailedJobStore $store,
        private readonly AppCache $cache,
    ) {
    }

    /** Cached snapshot, shared by the badge, the widgets and the page header. */
    public function status(): QueueHealthStatus
    {
        // AppCache::remember() is @template T, so the cached value is a QueueHealthStatus.
        return $this->cache->remember(
            self::CACHE_KEY,
            fn (): QueueHealthStatus => $this->collect(),
            self::CACHE_TTL,
        );
    }

    /**
     * Discard the cached snapshot — used after a retry, forget or prune changes the counts.
     */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return list<FailedJob>
     */
    public function recent(int $limit = 8): array
    {
        return $this->store->recent($limit);
    }

    private function collect(): QueueHealthStatus
    {
        return QueueHealthStatus::evaluate($this->depth(), $this->exhaustedCount());
    }

    /** Jobs waiting in the queue; null when the driver cannot be reached. */
    private function depth(): ?int
    {
        try {
            return $this->queue->pending();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Jobs that spent every attempt, or 0 when the table is not reachable.
     *
     * Deliberately not the row count: failed_jobs holds a job from its first failure, so
     * counting rows would put one orange tick on the sidebar for a job that recovers on the
     * next try. The badge has to mean "gave up", not "had a bad minute".
     */
    private function exhaustedCount(): int
    {
        try {
            return $this->store->countExhausted($this->queue->maxAttempts());
        } catch (\Throwable) {
            return 0;
        }
    }
}

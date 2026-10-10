<?php

declare(strict_types=1);

namespace Gereq\Queue\Application;

/**
 * Snapshot of queue health: the three-state status plus the numbers behind it.
 *
 * Passed around and cached as one value so the sidebar badge and the dashboard
 * widgets never run separate COUNT queries.
 */
final class QueueHealthStatus
{
    public const GREEN = 'green';
    public const ORANGE = 'orange';
    public const RED = 'red';

    /** Failed jobs at which the status degrades, and at which it becomes critical. */
    public const WARN_AT = 1;
    public const FAIL_AT = 5;

    public function __construct(
        public readonly string $status,
        public readonly int $failedCount,
        public readonly ?int $depth,
    ) {
    }

    /**
     * Pure decision, no I/O — the thresholds live here so they can be asserted
     * without a database, a cache or a queue driver.
     *
     * Unreachable queue storage counts as a failure of the queue, not of the admin.
     */
    public static function evaluate(?int $depth, int $failedCount): self
    {
        $status = match (true) {
            $depth === null => self::RED,
            $failedCount >= self::FAIL_AT => self::RED,
            $failedCount >= self::WARN_AT => self::ORANGE,
            default => self::GREEN,
        };

        return new self($status, $failedCount, $depth);
    }

    public function tone(): string
    {
        return match ($this->status) {
            self::RED => 'danger',
            self::ORANGE => 'warn',
            default => 'ok',
        };
    }

    /** Pre-rendered sidebar badge, or null when there is nothing to report. */
    public function menuBadge(): ?string
    {
        if ($this->failedCount < 1) {
            return null;
        }

        return sprintf('<span class="badge badge--%s">%d</span>', $this->tone(), $this->failedCount);
    }
}

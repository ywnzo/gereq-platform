<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

/**
 * Server-side sessionization.
 *
 * A client supplied session id is never trusted: a new session starts when there is no open
 * session for the visitor, or when the gap since `last_activity_at` **exceeds**
 * `analytics.session_idle_minutes` (a gap exactly equal to the window still continues it).
 */
final class Sessionizer
{
    public function shouldStartNewSession(
        \DateTimeImmutable|string|null $lastActivityAt,
        int $idleMinutes,
        ?\DateTimeImmutable $now = null,
    ): bool {
        if ($lastActivityAt === null) {
            return true;
        }

        $last = $this->toDate($lastActivityAt);
        if ($last === null) {
            return true;
        }

        return $this->isIdle($last, $idleMinutes, $now);
    }

    public function isIdle(
        \DateTimeImmutable $lastActivityAt,
        int $idleMinutes,
        ?\DateTimeImmutable $now = null,
    ): bool {
        $now ??= new \DateTimeImmutable();
        $idleSeconds = max(1, $idleMinutes) * 60;

        return ($now->getTimestamp() - $lastActivityAt->getTimestamp()) > $idleSeconds;
    }

    /**
     * Session duration in whole seconds, never negative.
     */
    public function durationSeconds(
        \DateTimeImmutable|string|null $startedAt,
        \DateTimeImmutable $lastActivityAt,
    ): int {
        $start = $this->toDate($startedAt);
        if ($start === null) {
            return 0;
        }

        return max(0, $lastActivityAt->getTimestamp() - $start->getTimestamp());
    }

    /**
     * A session is a bounce when it holds a single pageview and no interaction event.
     */
    public function isBounce(int $pageviewCount, int $eventCount): bool
    {
        return $pageviewCount <= 1 && $eventCount === 0;
    }

    private function toDate(\DateTimeImmutable|string|null $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}

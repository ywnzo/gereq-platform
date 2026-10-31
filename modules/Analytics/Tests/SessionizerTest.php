<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Modules\Analytics\Application\Sessionizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Server-side sessionization (README "Pseudonymity rules"): a new session starts when there is
 * no open session or the gap since `last_activity_at` **exceeds** `session_idle_minutes`.
 */
final class SessionizerTest extends TestCase
{
    private Sessionizer $sessionizer;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->sessionizer = new Sessionizer();
        $this->now = new \DateTimeImmutable('2026-10-18 12:00:00');
    }

    /**
     * @return array<string, array{0: ?string, 1: int, 2: bool}> [last activity spec, idle minutes, expected]
     */
    public static function idleBoundaryCases(): array
    {
        return [
            'no-previous-session' => [null, 30, true],
            'invalid-timestamp' => ['literal:not-a-date', 30, true],
            'brand-new-session' => ['offset:-0 seconds', 30, false],
            'well-inside-window' => ['offset:-100 seconds', 30, false],
            'one-second-inside-window' => ['offset:-1799 seconds', 30, false],
            'exactly-at-boundary-continues' => ['offset:-1800 seconds', 30, false],
            'one-second-over-boundary' => ['offset:-1801 seconds', 30, true],
            'zero-idle-minutes-clamps-to-one' => ['offset:-61 seconds', 0, true],
            'zero-idle-minutes-at-limit' => ['offset:-60 seconds', 0, false],
            'negative-idle-minutes-clamps-to-one' => ['offset:-61 seconds', -5, true],
        ];
    }

    #[DataProvider('idleBoundaryCases')]
    public function testShouldStartNewSessionAtTheIdleBoundary(?string $spec, int $idleMinutes, bool $expected): void
    {
        self::assertSame(
            $expected,
            $this->sessionizer->shouldStartNewSession($this->moment($spec), $idleMinutes, $this->now),
        );
    }

    public function testStringTimestampsAreAccepted(): void
    {
        self::assertFalse($this->sessionizer->shouldStartNewSession(
            $this->now->modify('-5 minutes')->format('Y-m-d H:i:s'),
            30,
            $this->now,
        ));
        self::assertTrue($this->sessionizer->shouldStartNewSession(
            $this->now->modify('-45 minutes')->format('Y-m-d H:i:s'),
            30,
            $this->now,
        ));
    }

    /** @return array<string, array{0: ?string, 1: string, 2: int}> */
    public static function durationCases(): array
    {
        return [
            'positive-duration' => ['offset:-100 seconds', '-0 seconds', 100],
            'zero-duration' => ['offset:-0 seconds', '-0 seconds', 0],
            'never-negative-on-clock-skew' => ['offset:+30 seconds', '-0 seconds', 0],
            'invalid-start' => ['literal:not-a-date', '-0 seconds', 0],
        ];
    }

    #[DataProvider('durationCases')]
    public function testDurationSecondsIsNeverNegative(?string $startSpec, string $lastOffset, int $expected): void
    {
        self::assertSame(
            $expected,
            $this->sessionizer->durationSeconds($this->moment($startSpec), $this->now->modify($lastOffset)),
        );
    }

    public function testDurationSecondsWithNullStartIsZero(): void
    {
        self::assertSame(0, $this->sessionizer->durationSeconds(null, $this->now));
    }

    /** @return array<string, array{0: int, 1: int, 2: bool}> */
    public static function bounceCases(): array
    {
        return [
            'empty-session' => [0, 0, true],
            'single-pageview-no-event' => [1, 0, true],
            'two-pageviews-no-event' => [2, 0, false],
            'single-pageview-with-event' => [1, 1, false],
            'no-pageview-with-event' => [0, 1, false],
            'busy-session' => [5, 3, false],
        ];
    }

    #[DataProvider('bounceCases')]
    public function testIsBounceTruthTable(int $pageviewCount, int $eventCount, bool $expected): void
    {
        self::assertSame($expected, $this->sessionizer->isBounce($pageviewCount, $eventCount));
    }

    public function testIsIdleUsesStrictlyGreaterThan(): void
    {
        self::assertFalse($this->sessionizer->isIdle($this->now->modify('-1800 seconds'), 30, $this->now));
        self::assertTrue($this->sessionizer->isIdle($this->now->modify('-1801 seconds'), 30, $this->now));
    }

    /**
     * Resolves a data-provider spec: `offset:…` is relative to now, `literal:…` is passed through
     * unchanged (used for the malformed input case).
     */
    private function moment(?string $spec): \DateTimeImmutable|string|null
    {
        if ($spec === null) {
            return null;
        }
        if (str_starts_with($spec, 'offset:')) {
            return $this->now->modify(substr($spec, strlen('offset:')));
        }

        return substr($spec, strlen('literal:'));
    }
}

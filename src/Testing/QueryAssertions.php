<?php

declare(strict_types=1);

namespace Gereq\Testing;

use Gereq\Debug\QueryCollector;

/**
 * Assertions for query budgets and N+1 detection (core + module tests).
 */
trait QueryAssertions
{
    protected function assertNoNPlusOne(QueryCollector $queries, string $message = ''): void
    {
        $hits = $queries->nPlusOnes();
        if ($hits === []) {
            $this->addToAssertionCount(1);

            return;
        }

        $parts = [];
        foreach ($hits as $hit) {
            $origin = $hit['origins'] !== [] ? implode(', ', $hit['origins']) : '(unknown origin)';
            $parts[] = sprintf('%dx · %s · %s', $hit['count'], $origin, $hit['sql']);
        }

        $detail = implode("\n", $parts);
        self::fail($message !== '' ? $message . "\n" . $detail : "Unexpected N+1 query pattern(s):\n" . $detail);
    }

    protected function assertQueryCountAtMost(QueryCollector $queries, int $max, string $message = ''): void
    {
        self::assertLessThanOrEqual(
            $max,
            $queries->count(),
            $message !== '' ? $message : sprintf(
                'Expected at most %d queries, got %d (%.2f ms).',
                $max,
                $queries->count(),
                $queries->totalMs(),
            ),
        );
    }

    protected function assertNPlusOneHitCount(QueryCollector $queries, int $expected, string $message = ''): void
    {
        self::assertSame(
            $expected,
            $queries->nPlusOneCount(),
            $message !== '' ? $message : sprintf(
                'Expected %d N+1 group(s), got %d.',
                $expected,
                $queries->nPlusOneCount(),
            ),
        );
    }
}

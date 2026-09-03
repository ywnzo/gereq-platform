<?php

declare(strict_types=1);

namespace Gereq\Debug;

final class QueryCollector
{
    /** @var list<array{sql: string, params: array<int|string, mixed>, duration: float, origin: string}> */
    private array $queries = [];

    public function add(string $sql, array $params, float $durationMs, string $origin = ''): void
    {
        $this->queries[] = [
            'sql' => $sql,
            'params' => $params,
            'duration' => round($durationMs, 2),
            'origin' => $origin,
        ];
    }

    /** @return list<array{sql: string, params: array<int|string, mixed>, duration: float, origin: string}> */
    public function all(): array
    {
        return $this->queries;
    }

    public function count(): int
    {
        return count($this->queries);
    }

    public function totalMs(): float
    {
        $sum = 0.0;
        foreach ($this->queries as $query) {
            $sum += $query['duration'];
        }

        return round($sum, 2);
    }
}

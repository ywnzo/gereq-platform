<?php

declare(strict_types=1);

namespace Gereq\Debug;

final class QueryCollector
{
    /** @var list<array{sql: string, params: array<int|string, mixed>, duration: float, origin: string}> */
    private array $queries = [];

    private ?NPlusOneDetector $detector = null;

    /** @var list<array{fingerprint: string, sql: string, count: int, totalMs: float, origins: list<string>, indices: list<int>}>|null */
    private ?array $nPlusOneCache = null;

    public function __construct(private readonly int $nPlusOneThreshold = 2)
    {
    }

    public function add(string $sql, array $params, float $durationMs, string $origin = ''): void
    {
        $this->queries[] = [
            'sql' => $sql,
            'params' => $params,
            'duration' => round($durationMs, 2),
            'origin' => $origin,
        ];
        $this->nPlusOneCache = null;
    }

    /**
     * @return list<array{sql: string, params: array<int|string, mixed>, duration: float, origin: string, nPlusOne: bool, nPlusOneCount: int}>
     */
    public function all(): array
    {
        $counts = $this->indexCounts();
        $rows = [];
        foreach ($this->queries as $index => $query) {
            $count = $counts[$index] ?? 0;
            $rows[] = [
                ...$query,
                'nPlusOne' => $count >= $this->nPlusOneThreshold,
                'nPlusOneCount' => $count,
            ];
        }

        return $rows;
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

    /**
     * @return list<array{fingerprint: string, sql: string, count: int, totalMs: float, origins: list<string>, indices: list<int>}>
     */
    public function nPlusOnes(): array
    {
        if ($this->nPlusOneCache !== null) {
            return $this->nPlusOneCache;
        }

        $this->nPlusOneCache = $this->detector()->detect($this->queries);

        return $this->nPlusOneCache;
    }

    public function nPlusOneCount(): int
    {
        return count($this->nPlusOnes());
    }

    public function nPlusOneThreshold(): int
    {
        return $this->nPlusOneThreshold;
    }

    /** @return array<int, int> index → počet výskytů stejného fingerprintu */
    private function indexCounts(): array
    {
        $counts = [];
        foreach ($this->nPlusOnes() as $hit) {
            foreach ($hit['indices'] as $index) {
                $counts[$index] = $hit['count'];
            }
        }

        return $counts;
    }

    private function detector(): NPlusOneDetector
    {
        return $this->detector ??= new NPlusOneDetector($this->nPlusOneThreshold);
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Debug;

/**
 * Detects likely N+1: the same (normalized) SQL fingerprint
 * repeats at least {@see $threshold} times in the request.
 */
final class NPlusOneDetector
{
    public function __construct(private readonly int $threshold = 2)
    {
    }

    /**
     * @param list<array{sql: string, params: array<int|string, mixed>, duration: float, origin: string}> $queries
     * @return list<array{
     *     fingerprint: string,
     *     sql: string,
     *     count: int,
     *     totalMs: float,
     *     origins: list<string>,
     *     indices: list<int>
     * }>
     */
    public function detect(array $queries): array
    {
        if ($this->threshold < 2 || $queries === []) {
            return [];
        }

        /** @var array<string, array{sql: string, count: int, totalMs: float, origins: array<string, true>, indices: list<int>}> $groups */
        $groups = [];

        foreach ($queries as $index => $query) {
            $fingerprint = self::fingerprint((string) $query['sql']);
            if (!isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'sql' => (string) $query['sql'],
                    'count' => 0,
                    'totalMs' => 0.0,
                    'origins' => [],
                    'indices' => [],
                ];
            }

            $groups[$fingerprint]['count']++;
            $groups[$fingerprint]['totalMs'] += (float) $query['duration'];
            $groups[$fingerprint]['indices'][] = $index;
            $origin = trim((string) ($query['origin'] ?? ''));
            if ($origin !== '') {
                $groups[$fingerprint]['origins'][$origin] = true;
            }
        }

        $hits = [];
        foreach ($groups as $fingerprint => $group) {
            if ($group['count'] < $this->threshold) {
                continue;
            }

            $hits[] = [
                'fingerprint' => $fingerprint,
                'sql' => $group['sql'],
                'count' => $group['count'],
                'totalMs' => round($group['totalMs'], 2),
                'origins' => array_keys($group['origins']),
                'indices' => $group['indices'],
            ];
        }

        usort($hits, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $hits;
    }

    /**
     * Normalizes SQL so differing literals / bind values in predicates
     * count as the same query (typical Doctrine N+1 with different ids).
     */
    public static function fingerprint(string $sql): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($sql)) ?? trim($sql);
        // string literals
        $normalized = preg_replace("/'(?:''|\\\\'|[^'])*'/u", '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/"(?:\\\\"|[^"])*"/u', '?', $normalized) ?? $normalized;
        // numbers only in predicates / value lists (=, <, >, ,, ()), not SELECT 1 / LIMIT n
        $normalized = preg_replace('/([=<>,\(]\s*)-?\d+(?:\.\d+)?\b/u', '${1}?', $normalized) ?? $normalized;
        // IN (?, ?, ?) → IN (?)
        $normalized = preg_replace('/\(\s*\?(?:\s*,\s*\?)*\s*\)/u', '(?)', $normalized) ?? $normalized;

        return strtolower($normalized);
    }
}

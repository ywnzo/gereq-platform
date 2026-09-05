<?php

declare(strict_types=1);

namespace Modules\Vitality\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Cache\AppCache;
use Gereq\Debug\QueryCollector;
use Modules\Vitality\Domain\EndpointStat;
use Modules\Vitality\Domain\SlowRequestLog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class VitalityMonitor
{
    public const SLOW_AVG_MS = 500.0;
    public const WARN_AVG_MS = 300.0;
    private const MAX_SLOW_LOGS = 500;
    private const MAX_QUERIES_STORED = 80;
    private const MAX_SQL_LENGTH = 2000;
    private const STATUS_CACHE_KEY = 'vitality.overall_status';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QueryCollector $queries,
        private readonly AppCache $cache,
    ) {
    }

    public function record(Request $request, Response $response, int $durationMs, int $queryOffset): void
    {
        $method = strtoupper($request->getMethod());
        $rawPath = $request->getPathInfo();
        $path = $this->normalizePath($rawPath);
        $routeName = (string) $request->attributes->get('_route', '');
        $endpointKey = $method . ' ' . $path;

        $captured = $this->captureQueries($queryOffset);

        $stat = $this->em->find(EndpointStat::class, $endpointKey);
        if (!$stat instanceof EndpointStat) {
            $stat = new EndpointStat($endpointKey, $method, $path, $routeName !== '' ? $routeName : null);
            $this->em->persist($stat);
        } else {
            $stat->setRouteName($routeName !== '' ? $routeName : null);
        }

        $stat->recordSample($durationMs);

        $loggedSlow = false;
        if ($stat->avgDurationMs() >= self::SLOW_AVG_MS) {
            $this->em->persist(new SlowRequestLog(
                endpointKey: $endpointKey,
                method: $method,
                path: $rawPath,
                routeName: $routeName !== '' ? $routeName : null,
                statusCode: $response->getStatusCode(),
                durationMs: $durationMs,
                avgAtTimeMs: $stat->avgDurationMs(),
                queryCount: count($captured),
                queryTotalMs: $this->sumQueryMs($captured),
                queries: $captured,
                memoryPeakBytes: memory_get_peak_usage(true),
                responseBytes: $this->responseBytes($response),
                correlationId: (string) $request->attributes->get('correlation_id', ''),
                requestQuery: $this->sanitizeQuery($request->query->all()),
            ));
            $loggedSlow = true;
        }

        $this->em->flush();
        $this->cache->forget(self::STATUS_CACHE_KEY);

        if ($loggedSlow) {
            $this->pruneSlowLogs();
            $this->em->flush();
        }
    }

    public function overallStatus(): string
    {
        return (string) $this->cache->remember(self::STATUS_CACHE_KEY, function (): string {
            try {
                $max = $this->em->createQueryBuilder()
                    ->select('MAX(s.avgDurationMs)')
                    ->from(EndpointStat::class, 's')
                    ->getQuery()
                    ->getSingleScalarResult();
            } catch (\Throwable) {
                return 'green';
            }

            $max = (float) ($max ?? 0);
            if ($max >= self::SLOW_AVG_MS) {
                return 'red';
            }
            if ($max >= self::WARN_AVG_MS) {
                return 'orange';
            }

            return 'green';
        }, 15);
    }

    /** @return list<EndpointStat> */
    public function endpointStats(int $limit = 100): array
    {
        return $this->em->getRepository(EndpointStat::class)->findBy(
            [],
            ['avgDurationMs' => 'DESC'],
            $limit,
        );
    }

    /** @return list<SlowRequestLog> */
    public function recentSlowLogs(int $limit = 50): array
    {
        return $this->em->getRepository(SlowRequestLog::class)->findBy(
            [],
            ['createdAt' => 'DESC'],
            $limit,
        );
    }

    public function findSlowLog(string $id): ?SlowRequestLog
    {
        $log = $this->em->find(SlowRequestLog::class, $id);

        return $log instanceof SlowRequestLog ? $log : null;
    }

    public function reset(): void
    {
        $this->em->createQuery('DELETE FROM ' . SlowRequestLog::class)->execute();
        $this->em->createQuery('DELETE FROM ' . EndpointStat::class)->execute();
        $this->cache->forget(self::STATUS_CACHE_KEY);
    }

    public function statusBadgeHtml(string $status, string $title = ''): string
    {
        return self::heartBadgeHtml($status, $title);
    }

    public static function heartBadgeHtml(string $status, string $title = '', string $size = 'sm'): string
    {
        $safe = in_array($status, ['green', 'orange', 'red'], true) ? $status : 'green';
        $fill = match ($safe) {
            'red' => '#ef4444',
            'orange' => '#f59e0b',
            default => '#22c55e',
        };
        $px = match ($size) {
            'lg' => 16,
            'md' => 14,
            default => 11,
        };
        $classes = 'vitality-heart vitality-heart--' . $safe . ' vitality-heart--' . ($size !== '' ? $size : 'sm');
        $titleAttr = $title !== '' ? ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"' : '';

        return '<span class="' . $classes . '"' . $titleAttr . ' aria-hidden="true">'
            . '<svg class="vitality-heart__svg" width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" focusable="false" xmlns="http://www.w3.org/2000/svg">'
            . '<path fill="' . $fill . '" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>'
            . '</svg></span>';
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace(
            '#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}#i',
            '/{id}',
            $path,
        ) ?? $path;

        return preg_replace('#/\d+#', '/{id}', $path) ?? $path;
    }

    /**
     * @return list<array{sql: string, duration: float, origin: string}>
     */
    private function captureQueries(int $offset): array
    {
        $all = $this->queries->all();
        $slice = array_slice($all, max(0, $offset));
        $out = [];
        foreach (array_slice($slice, 0, self::MAX_QUERIES_STORED) as $query) {
            $sql = (string) ($query['sql'] ?? '');
            if (strlen($sql) > self::MAX_SQL_LENGTH) {
                $sql = substr($sql, 0, self::MAX_SQL_LENGTH) . '…';
            }
            $out[] = [
                'sql' => $sql,
                'duration' => (float) ($query['duration'] ?? 0),
                'origin' => (string) ($query['origin'] ?? ''),
            ];
        }

        return $out;
    }

    /** @param list<array{sql: string, duration: float, origin: string}> $queries */
    private function sumQueryMs(array $queries): float
    {
        $sum = 0.0;
        foreach ($queries as $query) {
            $sum += $query['duration'];
        }

        return round($sum, 2);
    }

    private function responseBytes(Response $response): int
    {
        $length = $response->headers->get('Content-Length');
        if ($length !== null && is_numeric($length)) {
            return (int) $length;
        }

        return strlen($response->getContent() ?: '');
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function sanitizeQuery(array $query): array
    {
        $sensitive = ['password', 'token', 'secret', 'authorization', 'api_key', 'apikey'];
        $clean = [];
        foreach ($query as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($sensitive as $needle) {
                if (str_contains($lower, $needle)) {
                    $clean[(string) $key] = '[redacted]';
                    continue 2;
                }
            }
            if (is_scalar($value) || $value === null) {
                $clean[(string) $key] = $value;
            } else {
                $clean[(string) $key] = '[complex]';
            }
        }

        return $clean;
    }

    private function pruneSlowLogs(): void
    {
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(SlowRequestLog::class, 'l')
            ->getQuery()
            ->getSingleScalarResult();

        if ($count < self::MAX_SLOW_LOGS) {
            return;
        }

        $overflow = $count - self::MAX_SLOW_LOGS + 1;
        $old = $this->em->getRepository(SlowRequestLog::class)->findBy(
            [],
            ['createdAt' => 'ASC'],
            $overflow,
        );
        foreach ($old as $log) {
            $this->em->remove($log);
        }
    }
}

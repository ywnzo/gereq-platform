<?php

declare(strict_types=1);

namespace Gereq\Debug;

/**
 * Append-only JSONL log of N+1 hits (full SQL).
 * Soubor: storage/logs/nplusone.log
 */
final class NPlusOneLog
{
    private const DEFAULT_MINUTES = 20;

    private static ?string $path = null;

    /**
     * @param list<array{
     *     fingerprint: string,
     *     sql: string,
     *     count: int,
     *     totalMs: float,
     *     origins: list<string>,
     *     indices?: list<int>
     * }> $hits
     */
    public static function record(string $method, string $pathInfo, array $hits): void
    {
        if ($hits === []) {
            return;
        }

        $file = self::path();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $now = time();
        $iso = date('c', $now);
        $buf = '';
        foreach ($hits as $hit) {
            $row = [
                'ts' => $iso,
                'unix' => $now,
                'method' => $method,
                'path' => $pathInfo,
                'count' => (int) $hit['count'],
                'totalMs' => (float) $hit['totalMs'],
                'origins' => array_values($hit['origins'] ?? []),
                'fingerprint' => (string) ($hit['fingerprint'] ?? ''),
                'sql' => (string) $hit['sql'],
            ];
            try {
                $buf .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            } catch (\JsonException) {
                // skip malformed row
            }
        }

        if ($buf !== '') {
            @file_put_contents($file, $buf, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @return list<array{
     *     ts: string,
     *     unix: int,
     *     method: string,
     *     path: string,
     *     count: int,
     *     totalMs: float,
     *     origins: list<string>,
     *     fingerprint: string,
     *     sql: string
     * }>
     */
    public static function recent(int $minutes = self::DEFAULT_MINUTES): array
    {
        $minutes = max(1, $minutes);
        $file = self::path();
        if (!is_file($file)) {
            return [];
        }

        $cutoff = time() - ($minutes * 60);
        $out = [];
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    /** @var array<string, mixed> $row */
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                $unix = (int) ($row['unix'] ?? 0);
                if ($unix < $cutoff) {
                    continue;
                }
                $origins = $row['origins'] ?? [];
                if (!is_array($origins)) {
                    $origins = [];
                }
                $out[] = [
                    'ts' => (string) ($row['ts'] ?? date('c', $unix)),
                    'unix' => $unix,
                    'method' => (string) ($row['method'] ?? ''),
                    'path' => (string) ($row['path'] ?? ''),
                    'count' => (int) ($row['count'] ?? 0),
                    'totalMs' => (float) ($row['totalMs'] ?? 0),
                    'origins' => array_values(array_map('strval', $origins)),
                    'fingerprint' => (string) ($row['fingerprint'] ?? ''),
                    'sql' => (string) ($row['sql'] ?? ''),
                ];
            }
        } finally {
            fclose($handle);
        }

        return $out;
    }

    /**
     * Text dump for CLI / TUI (full SQL, no truncation).
     *
     * @return list<string>
     */
    public static function formatRecent(int $minutes = self::DEFAULT_MINUTES): array
    {
        $hits = self::recent($minutes);
        if ($hits === []) {
            return [sprintf('Žádné N+1 za posledních %d min.', $minutes)];
        }

        $lines = [
            sprintf('N+1 za posledních %d min · %d zásahů', $minutes, count($hits)),
            str_repeat('─', 72),
        ];

        foreach ($hits as $i => $hit) {
            $origins = $hit['origins'] !== [] ? implode(', ', $hit['origins']) : '—';
            $time = date('H:i:s', $hit['unix']);
            $lines[] = sprintf(
                '#%d  %s  %s %s  ·  %dx  ·  %s ms',
                $i + 1,
                $time,
                $hit['method'],
                $hit['path'],
                $hit['count'],
                $hit['totalMs'],
            );
            $lines[] = '     origin: ' . $origins;
            $lines[] = '     ' . $hit['sql'];
            $lines[] = '';
        }

        return $lines;
    }

    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        try {
            if (function_exists('base_path')) {
                self::$path = base_path('storage/logs/nplusone.log');

                return self::$path;
            }
        } catch (\Throwable) {
            // fallback below
        }

        self::$path = dirname(__DIR__, 2) . '/storage/logs/nplusone.log';

        return self::$path;
    }

    /** @internal tests */
    public static function setPath(?string $path): void
    {
        self::$path = $path;
    }
}

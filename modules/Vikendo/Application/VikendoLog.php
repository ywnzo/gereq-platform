<?php

declare(strict_types=1);

namespace Modules\Vikendo\Application;

/**
 * Append-only log průběhu Vikendo workeru / importu.
 * Soubor: storage/logs/vikendo.log
 */
final class VikendoLog
{
    private static ?string $path = null;

    private static ?string $runId = null;

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $event, array $context = []): void
    {
        self::write('INFO', $event, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warn(string $event, array $context = []): void
    {
        self::write('WARN', $event, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $event, array $context = []): void
    {
        self::write('ERROR', $event, $context);
    }

    public static function beginRun(): string
    {
        try {
            self::$runId = bin2hex(random_bytes(4));
        } catch (\Throwable) {
            self::$runId = substr(uniqid('', true), -8);
        }

        return self::$runId;
    }

    public static function endRun(): void
    {
        self::$runId = null;
    }

    public static function hasRun(): bool
    {
        return self::$runId !== null;
    }

    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        try {
            if (function_exists('base_path')) {
                self::$path = base_path('storage/logs/vikendo.log');

                return self::$path;
            }
        } catch (\Throwable) {
            // fallback below
        }

        self::$path = dirname(__DIR__, 3) . '/storage/logs/vikendo.log';

        return self::$path;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $event, array $context): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = (string) $key . '=' . self::formatValue($value);
        }

        $rid = self::$runId ?? '-';
        $line = sprintf(
            '[%s] %s rid=%s %s%s',
            date('Y-m-d H:i:s'),
            $level,
            $rid,
            $event,
            $parts !== [] ? ' ' . implode(' ', $parts) : '',
        );

        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        // Viditelné i ve výstupu `queue:work` / PHP-FPM stderr.
        error_log('vikendo: ' . $event . ($parts !== [] ? ' ' . implode(' ', $parts) : ''));
    }

    private static function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $flat = [];
            foreach ($value as $item) {
                if (is_scalar($item) || $item === null) {
                    $flat[] = (string) $item;
                }
            }

            return '[' . implode(',', $flat) . ']';
        }
        if ($value instanceof \Throwable) {
            return '"' . self::escape($value->getMessage()) . '"';
        }

        $str = (string) $value;
        if (strlen($str) > 500) {
            $str = substr($str, 0, 500) . '…';
        }

        return '"' . self::escape($str) . '"';
    }

    private static function escape(string $value): string
    {
        return str_replace(["\n", "\r", '"'], [' ', ' ', "'"], $value);
    }

    /**
     * Načte nové řádky logu od byte offsetu (pro polling v adminu).
     *
     * @return array{offset: int, size: int, lines: list<string>, truncated: bool}
     */
    public static function tail(int $offset = 0, int $maxBytes = 96_000): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return ['offset' => 0, 'size' => 0, 'lines' => [], 'truncated' => false];
        }

        $size = (int) filesize($path);
        if ($size <= 0) {
            return ['offset' => 0, 'size' => 0, 'lines' => [], 'truncated' => false];
        }

        $truncated = false;
        if ($offset < 0 || $offset > $size) {
            $offset = max(0, $size - min($maxBytes, $size));
            $truncated = $offset > 0;
        } elseif (($size - $offset) > $maxBytes) {
            $offset = $size - $maxBytes;
            $truncated = true;
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return ['offset' => $size, 'size' => $size, 'lines' => [], 'truncated' => false];
        }
        if ($offset > 0) {
            fseek($fh, $offset);
        }
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);

        $lines = preg_split("/\r\n|\n|\r/", $chunk) ?: [];
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        // Při skoku doprostřed souboru zahodíme první neúplný řádek.
        if ($truncated && $lines !== [] && $offset > 0) {
            array_shift($lines);
        }

        return [
            'offset' => $size,
            'size' => $size,
            'lines' => array_values($lines),
            'truncated' => $truncated,
        ];
    }

    public static function clear(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @file_put_contents($path, '');
        }
    }

    /** Odhad, jestli právě běží import (start bez finálního stavu ve zbytku logu). */
    public static function apparentlyRunning(): bool
    {
        $tail = self::tail(max(0, (is_file(self::path()) ? (int) filesize(self::path()) : 0) - 12_000));
        $started = false;
        foreach ($tail['lines'] as $line) {
            if (str_contains($line, ' job.start ')) {
                $started = true;
            }
            if (
                str_contains($line, ' job.done ')
                || str_contains($line, ' job.skip ')
                || str_contains($line, ' job.abort ')
                || str_contains($line, ' job.import.exception ')
            ) {
                $started = false;
            }
        }

        return $started;
    }
}

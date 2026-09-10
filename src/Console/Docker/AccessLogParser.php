<?php

declare(strict_types=1);

namespace Gereq\Console\Docker;

final class AccessLogParser
{
    private const NGINX = '/^(?P<svc>[a-z0-9_-]+(?:-\d+)?)\s+\|\s+\S+\s+.*?\[(?P<time>[^\]]+)\]\s+"(?P<method>[A-Z]+)\s+(?P<path>\S+)\s+HTTP\/[\d.]+"\s+(?P<status>\d{3})(?:\s+(?P<bytes>\d+|-))?/';

    /** PHP-FPM access: `php-1 | 172.x -  05/Sep/2026:08:51:47 +0000 "GET /index.php" 200` */
    private const PHP_FPM = '/^(?P<svc>[a-z0-9_-]+(?:-\d+)?)\s+\|\s+\S+\s+-\s+(?P<time>\d{2}\/\w+\/\d{4}:\d{2}:\d{2}:\d{2}\s+[+-]\d{4})\s+"(?P<method>[A-Z]+)\s+(?P<path>\S+)"\s+(?P<status>\d{3})/';

    public function parse(string $line): AccessLogEntry
    {
        $line = rtrim($line, "\r\n");

        foreach ([self::NGINX, self::PHP_FPM] as $pattern) {
            if (preg_match($pattern, $line, $m) !== 1) {
                continue;
            }

            $svc = $this->normalizeService((string) $m['svc']);
            $bytesRaw = $m['bytes'] ?? '-';
            $bytes = $bytesRaw === '-' || $bytesRaw === '' ? null : (int) $bytesRaw;
            $path = (string) $m['path'];
            if (str_contains($path, '?')) {
                $path = explode('?', $path, 2)[0];
            }
            // Vite HMR via nginx proxy → "hot" column (not nginx).
            if ($svc === 'nginx' && preg_match('#^/(@vite|@fs|@id|node_modules|resources|modules)(/|$)#', $path) === 1) {
                $svc = 'hot';
            }

            return new AccessLogEntry(
                service: $svc,
                method: (string) $m['method'],
                path: $path,
                status: (int) $m['status'],
                bytes: $bytes,
                time: $this->shortTime((string) $m['time']),
                raw: $line,
                access: true,
            );
        }

        $service = 'sys';
        $message = $line;
        if (preg_match('/^(?P<svc>[a-z0-9_-]+(?:-\d+)?)\s+\|/', $line, $m) === 1) {
            $service = $this->normalizeService((string) $m['svc']);
            $message = trim((string) preg_replace('/^[a-z0-9_-]+(?:-\d+)?\s+\|\s*/', '', $line));
        }

        return new AccessLogEntry(
            service: $service,
            method: '',
            path: $message,
            status: 0,
            bytes: null,
            time: null,
            raw: $line,
            access: false,
        );
    }

    private function normalizeService(string $svc): string
    {
        $svc = strtolower($svc);
        $svc = (string) preg_replace('/-\d+$/', '', $svc);

        return match (true) {
            str_contains($svc, 'nginx') => 'nginx',
            str_contains($svc, 'php') => 'php',
            str_contains($svc, 'redis') => 'redis',
            str_contains($svc, 'queue') => 'queue',
            default => $svc,
        };
    }

    private function shortTime(string $time): string
    {
        if (preg_match('/:(\d{2}:\d{2}:\d{2})\s/', $time, $m) === 1) {
            return $m[1];
        }

        return date('H:i:s');
    }
}

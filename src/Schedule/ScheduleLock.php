<?php

declare(strict_types=1);

namespace Gereq\Schedule;

/**
 * File-based mutex for withoutOverlapping() schedule events.
 */
final class ScheduleLock
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function acquire(string $name, int $ttlSeconds): bool
    {
        $path = $this->path($name);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (is_file($path)) {
            $mtime = filemtime($path);
            if ($mtime !== false && (time() - $mtime) < $ttlSeconds) {
                return false;
            }
            @unlink($path);
        }

        $written = @file_put_contents($path, (string) getmypid(), LOCK_EX);

        return $written !== false;
    }

    public function release(string $name): void
    {
        $path = $this->path($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function path(string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?? 'event';

        return $this->basePath . '/storage/tmp/schedule-locks/' . $safe . '.lock';
    }
}

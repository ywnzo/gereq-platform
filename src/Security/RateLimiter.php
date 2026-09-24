<?php

declare(strict_types=1);

namespace Gereq\Security;

final class RateLimiter
{
    public function __construct(private readonly string $path)
    {
        $this->ensureDirectory();
    }

    public function hit(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $this->ensureDirectory();

        $file = $this->file($key);
        $now = time();
        $attempts = [];

        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            $attempts = is_array($decoded) ? $decoded : [];
        }

        $attempts = array_values(array_filter(
            $attempts,
            static fn (mixed $ts): bool => is_int($ts) && $ts > ($now - $decaySeconds)
        ));

        if (count($attempts) >= $maxAttempts) {
            return false;
        }

        $attempts[] = $now;
        // Fail open when the rate-limit store is not writable — otherwise every
        // request becomes 429 (common after root-owned mkdir on bind mounts).
        if (!is_dir($this->path) || !is_writable($this->path)) {
            error_log('RateLimiter: store not writable at ' . $this->path . '; allowing request.');

            return true;
        }
        $written = @file_put_contents($file, json_encode($attempts), LOCK_EX);
        if ($written === false) {
            error_log('RateLimiter: failed to write ' . $file . '; allowing request.');

            return true;
        }

        return true;
    }

    public function clear(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->path)) {
            @mkdir($this->path, 0777, true);
        }
        if (is_dir($this->path) && !is_writable($this->path)) {
            @chmod($this->path, 0777);
        }
    }

    private function file(string $key): string
    {
        return $this->path . '/' . hash('sha256', $key) . '.json';
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Security;

final class RateLimiter
{
    /** @var list<string> Key prefixes that must fail closed when the store is unusable. */
    private const SENSITIVE_PREFIXES = [
        'login:',
        'mfa:',
        'login-passkey:',
        'password-reset:',
        'store-auth:',
        'gopay-notify:',
        'ares:',
    ];

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
        $failClosed = $this->isSensitive($key);

        if (!is_dir($this->path) || !is_writable($this->path)) {
            error_log('RateLimiter: store not writable at ' . $this->path . '; '
                . ($failClosed ? 'denying sensitive request.' : 'allowing request.'));

            return !$failClosed;
        }
        $written = @file_put_contents($file, json_encode($attempts), LOCK_EX);
        if ($written === false) {
            error_log('RateLimiter: failed to write ' . $file . '; '
                . ($failClosed ? 'denying sensitive request.' : 'allowing request.'));

            return !$failClosed;
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

    private function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
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

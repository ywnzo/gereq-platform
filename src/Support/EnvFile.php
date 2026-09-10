<?php

declare(strict_types=1);

namespace Gereq\Support;

/** Small .env read/upsert helper (no full dotenv rewrite). */
final class EnvFile
{
    public function __construct(private readonly string $path)
    {
    }

    public function get(string $key): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }
        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) !== $key) {
                continue;
            }
            $v = trim($v);
            if (
                (str_starts_with($v, '"') && str_ends_with($v, '"'))
                || (str_starts_with($v, "'") && str_ends_with($v, "'"))
            ) {
                $v = substr($v, 1, -1);
            }

            return $v;
        }

        return null;
    }

    public function set(string $key, string $value): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $line = $key . '=' . $value;
        if (!is_file($this->path)) {
            file_put_contents($this->path, $line . "\n");

            return;
        }

        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException('Cannot read ' . $this->path);
        }

        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        if (preg_match($pattern, $raw)) {
            $raw = preg_replace($pattern, $line, $raw, 1) ?? $raw;
        } else {
            $raw = rtrim($raw) . "\n\n" . $line . "\n";
        }

        file_put_contents($this->path, $raw);
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Schedule;

/**
 * Persists which schedule entries are paused (skipped by schedule:run).
 * Manual "run now" still works while paused.
 */
final class ScheduleState
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function isPaused(string $name): bool
    {
        return isset($this->paused()[$name]);
    }

    public function pause(string $name): void
    {
        $paused = $this->paused();
        $paused[$name] = time();
        $this->write($paused);
    }

    public function resume(string $name): void
    {
        $paused = $this->paused();
        unset($paused[$name]);
        $this->write($paused);
    }

    /** @return array<string, int> name => paused_at unix */
    public function paused(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $name => $at) {
            if (is_string($name) && $name !== '' && is_int($at)) {
                $out[$name] = $at;
            }
        }

        return $out;
    }

    /** @param array<string, int> $paused */
    private function write(array $paused): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($paused, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            LOCK_EX,
        );
    }

    private function path(): string
    {
        return $this->basePath . '/storage/tmp/schedule-state.json';
    }
}

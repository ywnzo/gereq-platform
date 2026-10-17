<?php

declare(strict_types=1);

namespace Gereq\Module;

/**
 * Maps {@see ModuleFootprint::quickSignature()} → content footprint so admin
 * auto-update can skip full SHA walks when the tree mtimes/sizes are unchanged.
 */
final class ModuleFootprintCache
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    /**
     * @return array{quick: string, footprint: string}|null
     */
    public function read(string $moduleId): ?array
    {
        $path = $this->pathFor($moduleId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $quick = $decoded['quick'] ?? null;
        $footprint = $decoded['footprint'] ?? null;
        if (!is_string($quick) || $quick === '' || !is_string($footprint) || $footprint === '') {
            return null;
        }

        return ['quick' => $quick, 'footprint' => $footprint];
    }

    public function write(string $moduleId, string $quick, string $footprint): void
    {
        if ($quick === '' || $footprint === '') {
            return;
        }
        $path = $this->pathFor($moduleId);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $payload = json_encode(
            ['quick' => $quick, 'footprint' => $footprint],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        file_put_contents($path, $payload . "\n", LOCK_EX);
    }

    public function forget(string $moduleId): void
    {
        $path = $this->pathFor($moduleId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function pathFor(string $moduleId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $moduleId) ?? 'module';

        return rtrim($this->basePath, '/') . '/storage/framework/module-footprints/' . $safe . '.json';
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Module;

/**
 * On-disk inventory of the last installed module tree (path → sha256).
 * Used to list file-level changes when the live footprint drifts.
 *
 * @phpstan-type Inventory array<string, string>
 */
final class ModuleInstalledSnapshot
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function pathFor(string $moduleId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $moduleId) ?? 'module';

        return rtrim($this->basePath, '/') . '/storage/modules/_snapshots/' . $safe . '.json';
    }

    /**
     * @return array{version: string, footprint: string, files: Inventory}|null
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
        $files = $decoded['files'] ?? null;
        if (!is_array($files)) {
            return null;
        }
        $normalized = [];
        foreach ($files as $rel => $digest) {
            if (!is_string($rel) || $rel === '' || !is_string($digest) || $digest === '') {
                continue;
            }
            $normalized[$rel] = $digest;
        }

        return [
            'version' => is_string($decoded['version'] ?? null) ? (string) $decoded['version'] : '',
            'footprint' => is_string($decoded['footprint'] ?? null) ? (string) $decoded['footprint'] : '',
            'files' => $normalized,
        ];
    }

    /**
     * @param Inventory $files
     */
    public function write(string $moduleId, string $version, string $footprint, array $files): void
    {
        $path = $this->pathFor($moduleId);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        ksort($files, SORT_STRING);
        $payload = json_encode([
            'version' => $version,
            'footprint' => $footprint,
            'files' => $files,
            'saved_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $payload . "\n", LOCK_EX);
    }
}

<?php

declare(strict_types=1);

namespace Gereq\View;

final class AssetManager
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function url(string $entry): string
    {
        $candidates = [
            $this->basePath . '/public/build/.vite/manifest.json',
            $this->basePath . '/public/build/manifest.json',
        ];

        foreach ($candidates as $manifestFile) {
            if (!is_file($manifestFile)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($manifestFile), true);
            if (!is_array($manifest)) {
                continue;
            }

            $key = $entry;
            if (!isset($manifest[$key]) && isset($manifest['resources/js/admin.ts'])) {
                $key = 'resources/js/admin.ts';
            }

            if (isset($manifest[$key])) {
                if (str_ends_with($entry, '.css') && isset($manifest[$key]['css'][0])) {
                    return '/build/' . ltrim((string) $manifest[$key]['css'][0], '/');
                }
                if (isset($manifest[$key]['file'])) {
                    return '/build/' . ltrim((string) $manifest[$key]['file'], '/');
                }
            }
        }

        if (str_ends_with($entry, '.css')) {
            return '/build/admin.css';
        }

        return '/build/admin.js';
    }
}

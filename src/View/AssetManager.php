<?php

declare(strict_types=1);

namespace Gereq\View;

use Symfony\Component\HttpFoundation\RequestStack;

final class AssetManager
{
    private const DEFAULT_VITE_PORT = 5173;

    public function __construct(
        private readonly string $basePath,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /** True when `bun run dev` left a `public/hot` marker. */
    public function isHot(): bool
    {
        return is_file($this->basePath . '/public/hot');
    }

    public function hotPort(): int
    {
        $hotFile = $this->basePath . '/public/hot';
        if (!is_file($hotFile)) {
            return self::DEFAULT_VITE_PORT;
        }

        $raw = trim((string) file_get_contents($hotFile));
        if ($raw !== '' && ctype_digit($raw)) {
            return max(1, (int) $raw);
        }

        if (preg_match('#:(\d+)\s*$#', $raw, $m) === 1) {
            return max(1, (int) $m[1]);
        }

        return self::DEFAULT_VITE_PORT;
    }

    /**
     * Public origin for Vite tags. Empty string = same-origin (nginx proxies to Vite).
     * Returns null when not in hot mode.
     */
    public function hotOrigin(): ?string
    {
        if (!$this->isHot()) {
            return null;
        }

        // Same-origin via nginx proxy — browser never talks to :5173 directly.
        return '';
    }

    /**
     * @return list<string>
     */
    public function hotOriginsForCsp(): array
    {
        if (!$this->isHot()) {
            return [];
        }

        // Same-origin HMR is covered by 'self'; keep localhost Vite as fallback.
        return [
            'http://127.0.0.1:' . $this->hotPort(),
            'http://localhost:' . $this->hotPort(),
        ];
    }

    public function url(string $entry): string
    {
        if ($this->isHot()) {
            return '/' . ltrim($entry, '/');
        }

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
            return '/build/kernel.css';
        }

        return '/build/admin.js';
    }

    /**
     * Script/link tags for admin entry — Vite client in hot mode, hashed build otherwise.
     */
    public function tags(string $entry = 'resources/js/admin.ts', string $nonce = ''): string
    {
        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';

        if ($this->isHot()) {
            // Blocking <link> prevents FOUC — CSS via JS modules alone paints unstyled HTML first.
            $css = '/resources/css/kernel.css';
            $client = '/@vite/client';
            $src = '/' . ltrim($entry, '/');

            return <<<HTML
<link rel="stylesheet" href="{$css}">
<script type="module"{$nonceAttr} src="{$client}"></script>
<script type="module"{$nonceAttr} src="{$src}"></script>
HTML;
        }

        $cssEntry = str_ends_with($entry, '.ts')
            ? 'resources/css/kernel.css'
            : $entry;
        $css = htmlspecialchars($this->url($cssEntry), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars($this->url($entry), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<link rel="stylesheet" href="{$css}">
<script type="module"{$nonceAttr} src="{$js}"></script>
HTML;
    }
}

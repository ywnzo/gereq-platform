<?php

declare(strict_types=1);

namespace Gereq\View;

use Gereq\Support\UrlPrefix;
use Symfony\Component\HttpFoundation\RequestStack;

final class AssetManager
{
    private const DEFAULT_VITE_PORT = 5173;

    public function __construct(
        private readonly string $basePath,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /** True when a legacy Vite-era `public/hot` marker is present (Bun watch does not write it). */
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
     * Public origin for legacy hot tags. Empty string = same-origin.
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

        // Same-origin hot is covered by 'self'; keep localhost ports as fallback.
        return [
            'http://127.0.0.1:' . $this->hotPort(),
            'http://localhost:' . $this->hotPort(),
        ];
    }

    public function url(string $entry): string
    {
        if ($this->isHot()) {
            return UrlPrefix::path('/' . ltrim($entry, '/'));
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
                    return UrlPrefix::path('/build/' . ltrim((string) $manifest[$key]['css'][0], '/'));
                }
                if (isset($manifest[$key]['file'])) {
                    return UrlPrefix::path('/build/' . ltrim((string) $manifest[$key]['file'], '/'));
                }
            }
        }

        if (str_ends_with($entry, '.css')) {
            return UrlPrefix::path('/build/kernel.css');
        }

        return UrlPrefix::path('/build/admin.js');
    }

    /**
     * Script/link tags for admin entry — legacy hot client, or hashed Bun build.
     */
    public function tags(string $entry = 'resources/js/admin.ts', string $nonce = ''): string
    {
        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';

        if ($this->isHot()) {
            $client = UrlPrefix::path('/@vite/client');
            $src = UrlPrefix::path('/' . ltrim($entry, '/'));
            $css = UrlPrefix::path('/resources/css/kernel.css');
            $cssLink = "<link rel=\"stylesheet\" href=\"{$css}\">\n";

            return <<<HTML
{$cssLink}<script type="module"{$nonceAttr} src="{$client}"></script>
<script type="module"{$nonceAttr} src="{$src}"></script>
HTML;
        }

        $tags = '';
        $manifestCss = $this->manifestCss($entry);
        foreach ($manifestCss as $href) {
            $css = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
            $tags .= "<link rel=\"stylesheet\" href=\"{$css}\">\n";
        }
        if ($tags === '') {
            $cssEntry = str_ends_with($entry, '.ts')
                ? 'resources/css/kernel.css'
                : $entry;
            $css = htmlspecialchars($this->url($cssEntry), ENT_QUOTES, 'UTF-8');
            $tags .= "<link rel=\"stylesheet\" href=\"{$css}\">\n";
        }
        $js = htmlspecialchars($this->url($entry), ENT_QUOTES, 'UTF-8');

        return $tags . "<script type=\"module\"{$nonceAttr} src=\"{$js}\"></script>";
    }

    /** @return list<string> */
    private function manifestCss(string $entry): array
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
            if (!is_array($manifest) || !isset($manifest[$entry]) || !is_array($manifest[$entry]['css'] ?? null)) {
                continue;
            }
            $hrefs = [];
            foreach ($manifest[$entry]['css'] as $file) {
                $hrefs[] = UrlPrefix::path('/build/' . ltrim((string) $file, '/'));
            }

            return $hrefs;
        }

        return [];
    }
}

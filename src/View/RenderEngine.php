<?php

declare(strict_types=1);

namespace Gereq\View;

use Gereq\Config\Repository;

/**
 * Resolves hashed / watch frontend assets from public/build/manifest.json.
 * Origins and URL prefixes come from config/render.php — never from Vite markers.
 */
final class RenderEngine
{
    public function __construct(
        private readonly string $basePath,
        private readonly Repository $config,
    ) {
    }

    public function isWatch(): bool
    {
        return is_file($this->absolute($this->string('dev_marker', 'public/build/.dev')));
    }

    public function url(string $entry): string
    {
        $key = $this->resolveEntry($entry);
        $cssRequest = str_ends_with($entry, '.css') || $key === $this->resolveEntry('css');

        $record = $this->manifestRecord($key);
        if ($record !== null) {
            if ($cssRequest) {
                $css = $record['css'][0] ?? null;
                if (is_string($css) && $css !== '') {
                    return $this->buildUrl($css);
                }
            }
            $file = $record['file'] ?? null;
            if (is_string($file) && $file !== '') {
                return $this->buildUrl($file);
            }
        }

        if ($cssRequest) {
            return $this->buildUrl('assets/kernel.css');
        }

        return $this->buildUrl('assets/admin.js');
    }

    public function cssTags(string $entry = 'admin', string $nonce = ''): string
    {
        $tags = '';
        foreach ($this->cssHrefs($entry) as $href) {
            $css = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
            $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
            $tags .= "<link rel=\"stylesheet\" href=\"{$css}\"{$nonceAttr}>\n";
        }

        return $tags;
    }

    public function scriptTags(string $entry = 'admin', string $nonce = ''): string
    {
        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
        $js = htmlspecialchars($this->url($entry), ENT_QUOTES, 'UTF-8');

        return "<script type=\"module\"{$nonceAttr} src=\"{$js}\"></script>";
    }

    /** CSS + JS tags for pages that load the bundle in one place. */
    public function tags(string $entry = 'admin', string $nonce = ''): string
    {
        return $this->cssTags($entry, $nonce) . $this->scriptTags($entry, $nonce);
    }

    public function reloadTags(string $nonce = ''): string
    {
        if (!$this->isWatch() || !$this->watchReloadEnabled()) {
            return '';
        }

        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
        $interval = (string) $this->watchPollMs();

        $script = <<<JS
          (function () {
            var last = null;
            var url = {$this->jsString($this->manifestPublicUrl())};
            function tick() {
              fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now(), { cache: 'no-store' })
                .then(function (r) { return r.text(); })
                .then(function (t) {
                  if (last !== null && t !== last) {
                    location.reload();
                    return;
                  }
                  last = t;
                })
                .catch(function () {});
            }
            setInterval(tick, {$interval});
            tick();
          })();
        JS;

        return "<script{$nonceAttr}>{$script}</script>";
    }

    /** @return list<string> */
    public function cspOrigins(): array
    {
        $origins = [];
        $assetOrigin = $this->assetOrigin();
        if ($assetOrigin !== '') {
            $origins[] = $assetOrigin;
        }
        foreach ($this->configList('csp_origins') as $origin) {
            if ($origin !== '' && !in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    /** @return list<string> */
    private function cssHrefs(string $entry): array
    {
        $key = $this->resolveEntry($entry);
        $record = $this->manifestRecord($key);
        if ($record !== null && $record['css'] !== []) {
            $hrefs = [];
            foreach ($record['css'] as $file) {
                if ($file !== '') {
                    $hrefs[] = $this->buildUrl($file);
                }
            }
            if ($hrefs !== []) {
                return $hrefs;
            }
        }

        return [$this->buildUrl('assets/kernel.css')];
    }

    /**
     * @return array{file: string, css: list<string>}|null
     */
    private function manifestRecord(string $key): ?array
    {
        $manifest = $this->manifest();
        if ($manifest === []) {
            return null;
        }

        $lookup = $key;
        if (!isset($manifest[$lookup]) && isset($manifest['resources/js/admin.ts'])) {
            $lookup = 'resources/js/admin.ts';
        }
        $raw = $manifest[$lookup] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $file = isset($raw['file']) && is_string($raw['file']) ? $raw['file'] : '';
        $css = [];
        if (isset($raw['css']) && is_array($raw['css'])) {
            foreach ($raw['css'] as $item) {
                if (is_string($item) && $item !== '') {
                    $css[] = $item;
                }
            }
        }

        return ['file' => $file, 'css' => $css];
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $file = $this->absolute($this->string('manifest', 'public/build/manifest.json'));
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function buildUrl(string $relative): string
    {
        $path = $this->publicPath() . '/' . ltrim($relative, '/');
        $url = $this->assetOrigin() . $this->urlPrefix() . $path;
        if (!$this->isWatch()) {
            return $url;
        }

        $mtime = 0;
        $manifestFile = $this->absolute($this->string('manifest', 'public/build/manifest.json'));
        if (is_file($manifestFile)) {
            $mtime = (int) filemtime($manifestFile);
        }
        $dev = $this->absolute($this->string('dev_marker', 'public/build/.dev'));
        if (is_file($dev)) {
            $mtime = max($mtime, (int) filemtime($dev));
        }

        return $url . '?v=' . max(1, $mtime);
    }

    private function manifestPublicUrl(): string
    {
        return $this->assetOrigin() . $this->urlPrefix() . $this->publicPath() . '/manifest.json';
    }

    private function resolveEntry(string $entry): string
    {
        $entries = $this->config->get('render.entries', []);
        if (is_array($entries) && isset($entries[$entry]) && is_string($entries[$entry]) && $entries[$entry] !== '') {
            return $entries[$entry];
        }

        return $entry;
    }

    private function assetOrigin(): string
    {
        return rtrim($this->string('asset_origin'), '/');
    }

    private function urlPrefix(): string
    {
        $explicit = $this->string('url_prefix');
        if ($explicit !== '') {
            return $this->normalizePrefix($explicit);
        }

        $fromApp = $this->config->get('app.url_prefix', '');

        return is_string($fromApp) ? $this->normalizePrefix($fromApp) : '';
    }

    private function publicPath(): string
    {
        $path = $this->string('public_path', '/build');
        if ($path === '' || $path === '/') {
            return '/build';
        }

        return '/' . trim($path, '/');
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '' || $prefix === '/') {
            return '';
        }

        return '/' . trim($prefix, '/');
    }

    private function watchReloadEnabled(): bool
    {
        return (bool) $this->config->get('render.watch.reload', true);
    }

    private function watchPollMs(): int
    {
        return max(250, (int) $this->config->get('render.watch.poll_ms', 1200));
    }

    /** @return list<string> */
    private function configList(string $key): array
    {
        $raw = $this->config->get('render.' . $key, []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function string(string $key, string $default = ''): string
    {
        $value = $this->config->get('render.' . $key, $default);

        return is_string($value) ? $value : $default;
    }

    private function absolute(string $relative): string
    {
        return $this->basePath . '/' . ltrim($relative, '/');
    }

    private function jsString(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

<?php

declare(strict_types=1);

namespace Gereq\View;

use Gereq\Config\Repository;
use Gereq\Module\EnabledModulesProvider;

/**
 * Resolves hashed / watch frontend assets from public/build/manifest.json.
 * Origins and URL prefixes come from config/render.php — never from Vite markers.
 *
 * Kernel CSS comes from the admin entry; module CSS (`module:{id}`) is linked
 * only for modules currently enabled via EnabledModulesProvider.
 */
final class RenderEngine
{
    /** @var array<string, mixed>|null */
    private ?array $manifestCache = null;

    private bool $manifestLoaded = false;

    public function __construct(
        private readonly string $basePath,
        private readonly Repository $config,
        private readonly ?EnabledModulesProvider $modules = null,
    ) {
    }

    public function isWatch(): bool
    {
        return is_file($this->absolute($this->string('dev_marker', 'public/build/.dev')))
            || is_file($this->absolute($this->string('watch_state', 'public/build/watch.json')));
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

        return $this->moduleAssetsBootstrap($nonce)
            . "<script type=\"module\"{$nonceAttr} src=\"{$js}\"></script>";
    }

    /**
     * Inject enabled module JS URLs for kernel dynamic import before Alpine.start().
     *
     * @return list<string>
     */
    public function enabledModuleJsUrls(): array
    {
        if ($this->modules === null) {
            return [];
        }

        $urls = [];
        foreach ($this->modules->enabledIds() as $id) {
            $record = $this->manifestRecord('module:' . $id);
            if ($record === null) {
                continue;
            }
            $file = $record['file'];
            if ($file === '' || !str_ends_with($file, '.js')) {
                continue;
            }
            $urls[] = $this->buildUrl($file);
        }

        return $urls;
    }

    public function moduleAssetsBootstrap(string $nonce = ''): string
    {
        $urls = $this->enabledModuleJsUrls();
        try {
            $json = json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            $json = '[]';
        }
        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';

        return "<script{$nonceAttr}>window.__GEREQ_MODULE_ASSETS__={$json};</script>\n";
    }

    /** CSS + JS tags for pages that load the bundle in one place. */
    public function tags(string $entry = 'admin', string $nonce = ''): string
    {
        return $this->cssTags($entry, $nonce) . $this->scriptTags($entry, $nonce);
    }

    /**
     * Dev watch: poll public/build/watch.json.
     * CSS-only → hot-swap stylesheets; JS or Twig (`viewsAt`) → full reload.
     * With APP_DEBUG, a probe runs even before `bun run dev` so an already-open
     * tab can attach when watch starts (no manual refresh required).
     */
    public function reloadTags(string $nonce = ''): string
    {
        if (!$this->watchReloadEnabled()) {
            return '';
        }
        if (!$this->isWatch() && !$this->watchProbeWhenDebug()) {
            return '';
        }

        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
        $interval = (string) $this->watchPollMs();
        $probeInterval = (string) max($this->watchPollMs(), 2500);
        $buildPrefix = $this->jsString($this->urlPrefix() . $this->publicPath() . '/');

        $script = <<<JS
          (function () {
            var last = null;
            var connected = false;
            var url = {$this->jsString($this->watchStatePublicUrl())};
            var buildPrefix = {$buildPrefix};
            var pollMs = {$interval};
            var probeMs = {$probeInterval};
            function fpMap(files, ext) {
              var out = {};
              if (!files || typeof files !== 'object') return out;
              Object.keys(files).forEach(function (name) {
                if (name.slice(-ext.length).toLowerCase() === ext) {
                  out[name] = String(files[name]);
                }
              });
              return out;
            }
            function sameFp(a, b) {
              var ak = Object.keys(a).sort();
              var bk = Object.keys(b).sort();
              if (ak.length !== bk.length) return false;
              for (var i = 0; i < ak.length; i++) {
                if (ak[i] !== bk[i] || a[ak[i]] !== b[bk[i]]) return false;
              }
              return true;
            }
            function rehydrateCss() {
              var stamp = String(Date.now());
              var links = document.querySelectorAll('link[rel="stylesheet"][href]');
              for (var i = 0; i < links.length; i++) {
                var link = links[i];
                var href = link.getAttribute('href') || '';
                if (href.indexOf(buildPrefix) === -1 && href.indexOf('/build/') === -1) continue;
                try {
                  var abs = new URL(href, window.location.href);
                  abs.searchParams.set('v', stamp);
                  var next = link.cloneNode(false);
                  next.setAttribute('href', abs.pathname + abs.search + abs.hash);
                  next.onload = (function (old) {
                    return function () { if (old.parentNode) old.parentNode.removeChild(old); };
                  })(link);
                  if (link.parentNode) {
                    link.parentNode.insertBefore(next, link.nextSibling);
                  }
                } catch (e) {}
              }
              if (typeof console !== 'undefined' && console.info) {
                console.info('[gereq] css rehydrated');
              }
            }
            function tick() {
              return fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now(), { cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                  if (!data || typeof data !== 'object' || data.mode !== 'watch') {
                    if (connected && typeof console !== 'undefined' && console.info) {
                      console.info('[gereq] asset watch stopped');
                    }
                    connected = false;
                    last = null;
                    return;
                  }
                  var files = data.files && typeof data.files === 'object' ? data.files : {};
                  var css = fpMap(files, '.css');
                  var js = fpMap(files, '.js');
                  var viewsAt = typeof data.viewsAt === 'number' ? data.viewsAt : 0;
                  if (!connected) {
                    connected = true;
                    last = { css: css, js: js, viewsAt: viewsAt };
                    if (typeof console !== 'undefined' && console.info) {
                      console.info('[gereq] asset watch connected');
                    }
                    return;
                  }
                  if (last === null) {
                    last = { css: css, js: js, viewsAt: viewsAt };
                    return;
                  }
                  var viewsChanged = viewsAt !== last.viewsAt;
                  var jsChanged = !sameFp(last.js, js);
                  var cssChanged = !sameFp(last.css, css);
                  last = { css: css, js: js, viewsAt: viewsAt };
                  if (viewsChanged || jsChanged) {
                    if (typeof console !== 'undefined' && console.info) {
                      console.info('[gereq] reload (' + (viewsChanged ? 'twig' : 'js') + ')');
                    }
                    location.reload();
                    return;
                  }
                  if (cssChanged) {
                    rehydrateCss();
                  }
                })
                .catch(function () {
                  connected = false;
                  last = null;
                });
            }
            function loop() {
              var p = tick();
              var wait = function () { setTimeout(loop, connected ? pollMs : probeMs); };
              if (p && typeof p.finally === 'function') p.finally(wait); else wait();
            }
            loop();
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
        $hrefs = [];

        $record = $this->manifestRecord($key);
        if ($record !== null) {
            foreach ($record['css'] as $file) {
                if ($file !== '') {
                    $hrefs[] = $this->buildUrl($file);
                }
            }
        }

        if ($entry === 'admin' || $key === $this->resolveEntry('admin')) {
            foreach ($this->enabledModuleCssHrefs() as $href) {
                if (!in_array($href, $hrefs, true)) {
                    $hrefs[] = $href;
                }
            }
        }

        if ($hrefs !== []) {
            return $hrefs;
        }

        return [$this->buildUrl('assets/kernel.css')];
    }

    /** @return list<string> */
    private function enabledModuleCssHrefs(): array
    {
        if ($this->modules === null) {
            return [];
        }

        $hrefs = [];
        foreach ($this->modules->enabledIds() as $id) {
            $record = $this->manifestRecord('module:' . $id);
            if ($record === null) {
                continue;
            }
            foreach ($record['css'] as $file) {
                if ($file !== '') {
                    $hrefs[] = $this->buildUrl($file);
                }
            }
            if ($record['css'] === [] && $record['file'] !== '' && str_ends_with($record['file'], '.css')) {
                $hrefs[] = $this->buildUrl($record['file']);
            }
        }

        return $hrefs;
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
        if ($this->manifestLoaded) {
            return $this->manifestCache ?? [];
        }
        $this->manifestLoaded = true;
        $file = $this->absolute($this->string('manifest', 'public/build/manifest.json'));
        if (!is_file($file)) {
            return $this->manifestCache = [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return $this->manifestCache = is_array($decoded) ? $decoded : [];
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
        $watchState = $this->absolute($this->string('watch_state', 'public/build/watch.json'));
        if (is_file($watchState)) {
            $mtime = max($mtime, (int) filemtime($watchState));
        }

        return $url . '?v=' . max(1, $mtime);
    }

    private function watchStatePublicUrl(): string
    {
        return $this->assetOrigin() . $this->urlPrefix() . $this->publicPath() . '/watch.json';
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

    private function watchProbeWhenDebug(): bool
    {
        if (!(bool) $this->config->get('render.watch.probe_when_debug', true)) {
            return false;
        }

        return (bool) $this->config->get('app.debug', false);
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

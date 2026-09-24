<?php

declare(strict_types=1);

namespace Gereq\Console\Docker;

final class AccessLogEntry
{
    public function __construct(
        public readonly string $service,
        public readonly string $method,
        public readonly string $path,
        public readonly int $status,
        public readonly ?int $bytes,
        public readonly ?string $time,
        public readonly string $raw,
        public readonly bool $access,
    ) {
    }

    public function isAsset(): bool
    {
        if ($this->isHotProxy()) {
            return false;
        }
        if ((bool) preg_match('#^/(build|assets|brand|favicon)/#', $this->path)) {
            return true;
        }
        // Media files and admin thumbnails (…/media/{id}/file)
        if ((bool) preg_match('#/media/[^/]+/file$#', $this->path)) {
            return true;
        }

        return str_ends_with($this->path, '.css')
            || str_ends_with($this->path, '.js')
            || str_ends_with($this->path, '.map')
            || str_ends_with($this->path, '.woff2')
            || str_ends_with($this->path, '.ico');
    }

    /**
     * Legacy Vite HMR paths (nginx no longer proxies /@vite).
     * Kept so old access logs still filter as "hot" noise.
     */
    public function isHotProxy(): bool
    {
        if (!$this->access) {
            return false;
        }

        return (bool) preg_match('#^/(@vite|@fs|@id)(/|$)#', $this->path);
    }

    public function isPhpIndexNoise(): bool
    {
        return $this->service === 'php' && ($this->path === '/index.php' || $this->path === 'index.php');
    }

    /** nginx notices, redis RDB, Content-Length warn spam */
    public function isSystemNoise(): bool
    {
        if ($this->access) {
            return false;
        }
        $t = strtolower($this->path);

        return str_contains($t, 'start worker')
            || str_contains($t, 'using the "epoll"')
            || str_contains($t, 'getaddrinfo')
            || str_contains($t, 'background saving')
            || str_contains($t, 'fork cow')
            || str_contains($t, 'db saved on disk')
            || str_contains($t, 'content-length')
            || str_contains($t, 'signal 1')
            || str_contains($t, 'configuration complete')
            || str_contains($t, 'start worker processes')
            || (bool) preg_match('/\[notice\]/', $t);
    }

    public function isError(): bool
    {
        return $this->status >= 400;
    }

    public function sizeLabel(): string
    {
        if ($this->bytes === null || $this->bytes < 0) {
            return '—';
        }
        if ($this->bytes < 1024) {
            return $this->bytes . 'B';
        }
        if ($this->bytes < 1024 * 1024) {
            return rtrim(rtrim(number_format($this->bytes / 1024, 1, '.', ''), '0'), '.') . 'k';
        }

        return rtrim(rtrim(number_format($this->bytes / (1024 * 1024), 1, '.', ''), '0'), '.') . 'M';
    }
}

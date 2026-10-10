<?php

declare(strict_types=1);

namespace Gereq\Media\Application;

use Gereq\Config\Repository;
use Gereq\File\FileManager;
use Gereq\Media\Domain\MediaAsset;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** Serves media binaries (X-Accel, ETag, optional width resize). */
final class MediaFileDelivery
{
    /** @var list<int> */
    private const WIDTH_STEPS = [160, 320, 480, 640, 800, 960, 1200, 1600, 1920, 2400];

    public function __construct(
        private readonly FileManager $files,
        private readonly Repository $config,
        private readonly string $basePath,
    ) {
    }

    public function respond(MediaAsset $asset, Request $request, bool $public): Response
    {
        $this->releaseSession($request);

        $absolute = $this->absolutePath($asset->path());
        if ($absolute === null || !is_file($absolute)) {
            throw new \RuntimeException('media_file_missing');
        }

        if (str_starts_with($asset->mime(), 'image/')) {
            $optimized = $this->maybeServeOptimized($asset, $absolute, $request);
            if ($optimized !== null) {
                return $optimized;
            }
        }

        $filename = str_starts_with($asset->mime(), 'image/')
            ? null
            : $this->safeDownloadName($asset->title(), $asset->path());

        return $this->binaryFile(
            $request,
            $absolute,
            $asset->mime(),
            $public || str_starts_with($asset->mime(), 'image/'),
            $filename,
        );
    }

    /**
     * Resize (optional ?w=) and negotiate AVIF/WebP via Accept.
     * Format conversion runs even when the source is already small enough.
     */
    private function maybeServeOptimized(MediaAsset $asset, string $absolute, Request $request): ?Response
    {
        $converter = $this->files->converter();
        $forced = strtolower((string) $request->query->get('format', ''));
        if ($forced === 'webp' && $converter->supportsWebp()) {
            $ext = 'webp';
        } elseif ($forced === 'avif' && $converter->supportsAvif()) {
            $ext = 'avif';
        } else {
            $preferAvif = $converter->supportsAvif()
                && str_contains(strtolower($request->headers->get('Accept', '')), 'image/avif');
            $ext = $preferAvif ? 'avif' : ($converter->supportsWebp() ? 'webp' : null);
        }
        if ($ext === null) {
            return null;
        }

        // Already in the preferred modern format — serve original.
        $srcMime = strtolower($asset->mime());
        if (($ext === 'avif' && $srcMime === 'image/avif')
            || ($ext === 'webp' && $srcMime === 'image/webp')) {
            return null;
        }

        $target = $this->requestedWidthStep($request);
        $srcW = $asset->width();
        $needsResize = $target !== null
            && ($srcW === null || $srcW <= 0 || $srcW > $target);
        $cacheKey = $needsResize ? 'w' . $target : 'full';

        $cacheDir = $this->basePath . '/storage/cache/media/' . $asset->id();
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return null;
        }

        $cachePath = $cacheDir . '/' . $cacheKey . '.' . $ext;

        if (!is_file($cachePath) || filemtime($cachePath) < filemtime($absolute)) {
            $bytes = @file_get_contents($absolute);
            if (!is_string($bytes) || $bytes === '') {
                return null;
            }

            $workMime = $asset->mime();
            if ($needsResize && $target !== null) {
                $resized = $this->resizeToWidth($bytes, $workMime, $target);
                if ($resized === null) {
                    // Can't downscale (decoder/size) — still try format-only convert.
                    $needsResize = false;
                    $cacheKey = 'full';
                    $cachePath = $cacheDir . '/full.' . $ext;
                } else {
                    $bytes = $resized['contents'];
                    $workMime = $resized['mime'];
                }
            }

            $converted = $converter->convert($bytes, $workMime, $ext);
            if ($converted === null) {
                return null;
            }

            $cachePath = $cacheDir . '/' . $cacheKey . '.' . $converted['extension'];
            if (file_put_contents($cachePath, $converted['contents']) === false) {
                return null;
            }
            @chmod($cachePath, 0664);
        }

        $mime = match (strtolower(pathinfo($cachePath, PATHINFO_EXTENSION))) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };

        $response = $this->binaryFile($request, $cachePath, $mime, public: true);
        $response->headers->set('Vary', 'Accept');

        return $response;
    }

    private function requestedWidthStep(Request $request): ?int
    {
        $w = (int) $request->query->get('w', 0);
        if ($w < 64 || $w > 2400) {
            return null;
        }

        $target = self::WIDTH_STEPS[0];
        foreach (self::WIDTH_STEPS as $step) {
            $target = $step;
            if ($w <= $step) {
                break;
            }
        }

        return $target;
    }

    /**
     * @return array{contents: string, mime: string}|null
     */
    private function resizeToWidth(string $bytes, string $mime, int $targetWidth): ?array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) {
            return null;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $srcW = imagesx($src);
        if ($srcW <= $targetWidth) {
            imagedestroy($src);

            return null;
        }

        $dst = imagescale($src, $targetWidth);
        imagedestroy($src);
        if ($dst === false) {
            return null;
        }

        ob_start();
        $ok = imagejpeg($dst, null, 85);
        imagedestroy($dst);
        $out = ob_get_clean();
        if ($ok === false || !is_string($out) || $out === '') {
            return null;
        }

        return ['contents' => $out, 'mime' => 'image/jpeg'];
    }

    private function binaryFile(
        Request $request,
        string $absolute,
        string $mime,
        bool $public,
        ?string $filename = null,
    ): Response {
        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setAutoLastModified();
        $response->setAutoEtag();

        if ($public) {
            $ttl = max(60, (int) $this->config->get('cache.media_ttl', 2592000));
            $response->setPublic();
            $response->setMaxAge($ttl);
            $response->headers->set(
                'Cache-Control',
                sprintf('public, max-age=%d, immutable, stale-while-revalidate=%d', $ttl, min(86400, $ttl)),
            );
        } else {
            $response->setPrivate();
            $response->headers->set('Cache-Control', 'private, max-age=300');
        }

        if ($filename !== null && $filename !== '') {
            $disposition = $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $filename,
            );
            $response->headers->set('Content-Disposition', $disposition);
        }

        if ($response->isNotModified($request)) {
            return $response;
        }

        $accel = $this->accelRedirectPath($absolute);
        if ($accel !== null) {
            $light = new Response('', $response->getStatusCode());
            foreach (['Content-Type', 'Cache-Control', 'ETag', 'Last-Modified', 'X-Content-Type-Options', 'Content-Disposition', 'Vary'] as $header) {
                $value = $response->headers->get($header);
                if (is_string($value) && $value !== '') {
                    $light->headers->set($header, $value);
                }
            }
            $light->headers->set('X-Accel-Redirect', $accel);

            return $light;
        }

        $response->prepare($request);

        return $response;
    }

    private function accelRedirectPath(string $absolute): ?string
    {
        $software = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (!str_contains($software, 'nginx')) {
            return null;
        }

        $map = [
            '/internal-media/' => $this->basePath . '/' . trim((string) $this->config->get('files.local_path', 'storage/uploads'), '/'),
            '/internal-media-cache/' => $this->basePath . '/storage/cache/media',
        ];
        foreach ($map as $prefix => $root) {
            $real = realpath($root);
            if ($real === false || !str_starts_with($absolute, $real . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($absolute, strlen($real) + 1));

            return $prefix . $relative;
        }

        return null;
    }

    private function safeDownloadName(string $title, string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $title) ?? 'file';
        $ascii = trim($ascii, '-._') ?: 'file';
        if ($ext !== '' && !str_ends_with(strtolower($ascii), '.' . strtolower($ext))) {
            $ascii .= '.' . $ext;
        }

        return $ascii;
    }

    private function releaseSession(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        if ($session->isStarted()) {
            $session->save();
        }
    }

    private function absolutePath(string $relative): ?string
    {
        $root = realpath($this->basePath . '/' . trim((string) $this->config->get('files.local_path', 'storage/uploads'), '/'));
        if ($root === false) {
            return null;
        }
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
            return null;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\'], '/', $relative), '/');
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }
        if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}

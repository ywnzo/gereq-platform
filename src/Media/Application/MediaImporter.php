<?php

declare(strict_types=1);

namespace Gereq\Media\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\File\ImageConverter;
use Gereq\Media\Domain\MediaAsset;

/**
 * Downloads a remote image into storage/uploads and creates a MediaAsset.
 * Idempotent by URL hash path (seed/{sha1}.{ext}).
 * Images are converted to AVIF (or WebP fallback) when GD supports it.
 */
final class MediaImporter
{
    /** @var array<string, MediaAsset> */
    private array $cache = [];

    private ImageConverter $converter;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $uploadsRoot,
        ?ImageConverter $converter = null,
    ) {
        $this->converter = $converter ?? new ImageConverter();
    }

    public static function fromBasePath(EntityManagerInterface $em, string $basePath): self
    {
        $root = rtrim($basePath, '/') . '/storage/uploads';

        return new self($em, $root);
    }

    public function importFromUrl(string $url, string $title, ?string $alt = null): ?MediaAsset
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (isset($this->cache[$url])) {
            return $this->cache[$url];
        }

        $hash = sha1($url);
        $existing = $this->findByHash($hash);
        if ($existing instanceof MediaAsset) {
            $this->reconcilePathExtension($existing, $hash);
            $this->cache[$url] = $existing;

            return $existing;
        }

        $downloaded = $this->download($url);
        if ($downloaded === null) {
            return null;
        }

        [$bytes, $mime, $ext] = $downloaded;

        $converted = $this->converter->convert($bytes, $mime);
        if ($converted !== null) {
            $bytes = $converted['contents'];
            $mime = $converted['mime'];
            $ext = $converted['extension'];
        }

        $relative = 'seed/' . $hash . '.' . $ext;
        $absolute = $this->uploadsRoot . '/' . $relative;
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        if (!is_file($absolute)) {
            if (file_put_contents($absolute, $bytes) === false) {
                return null;
            }
        }

        $width = is_array($converted) ? ($converted['width'] ?? null) : null;
        $height = is_array($converted) ? ($converted['height'] ?? null) : null;
        if (($width === null || $height === null) && str_starts_with($mime, 'image/')) {
            $info = @getimagesize($absolute);
            if (is_array($info)) {
                $width = $info[0] ?? null;
                $height = $info[1] ?? null;
            }
        }

        $byPath = $this->em->getRepository(MediaAsset::class)->findOneBy(['path' => $relative]);
        if ($byPath instanceof MediaAsset) {
            $this->cache[$url] = $byPath;

            return $byPath;
        }

        $asset = new MediaAsset(
            $title,
            $relative,
            $mime,
            strlen($bytes),
            null,
            $alt ?? $title,
            $width,
            $height,
        );
        $this->em->persist($asset);
        $this->em->flush();
        $this->cache[$url] = $asset;

        return $asset;
    }

    private function findByHash(string $hash): ?MediaAsset
    {
        foreach (['avif', 'webp', 'jpg', 'jpeg', 'png', 'gif'] as $ext) {
            $path = 'seed/' . $hash . '.' . $ext;
            $asset = $this->em->getRepository(MediaAsset::class)->findOneBy(['path' => $path]);
            if ($asset instanceof MediaAsset) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Seed may store AVIF/WebP on disk while an older DB row still points at .jpg.
     */
    private function reconcilePathExtension(MediaAsset $asset, string $hash): void
    {
        $absolute = $this->uploadsRoot . '/' . ltrim($asset->path(), '/');
        if (is_file($absolute)) {
            return;
        }

        $extMime = [
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
        ];
        foreach ($extMime as $ext => $mime) {
            $relative = 'seed/' . $hash . '.' . $ext;
            $candidate = $this->uploadsRoot . '/' . $relative;
            if (!is_file($candidate)) {
                continue;
            }
            $asset->setPath($relative);
            $asset->setMime($mime);
            $asset->setSize((int) filesize($candidate));
            $this->em->flush();

            return;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null bytes, mime, ext
     */
    private function download(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'header' => "User-Agent: RealEstates-Seeder/1.0\r\nAccept: image/*\r\n",
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $bytes = @file_get_contents($url, false, $context);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $mime = 'image/jpeg';
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^Content-Type:\s*([^\s;]+)/i', $header, $m)) {
                    $mime = strtolower($m[1]);
                    break;
                }
            }
        }

        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($bytes);
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
                $mime = $detected;
            }
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            'image/jpeg', 'image/jpg' => 'jpg',
            default => 'jpg',
        };

        if (!str_starts_with($mime, 'image/')) {
            $mime = 'image/jpeg';
            $ext = 'jpg';
        }

        return [$bytes, $mime, $ext];
    }
}

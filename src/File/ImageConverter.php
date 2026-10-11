<?php

declare(strict_types=1);

namespace Gereq\File;

/**
 * Prefers AVIF; falls back to WebP when unavailable or on failure.
 * Leaves GIFs and non-images unchanged (animation / unsupported input).
 */
final class ImageConverter
{
    private const CONVERTIBLE = [
        'image/jpeg',
        'image/jpg',
        'image/pjpeg',
        'image/png',
        'image/webp',
        'image/avif',
    ];

    public function __construct(
        private readonly int $avifQuality = 55,
        private readonly int $webpQuality = 90,
    ) {
    }

    /**
     * @param 'avif'|'webp'|null $prefer Force a format; null = AVIF then WebP.
     *
     * @return array{contents: string, mime: string, extension: string, width: ?int, height: ?int}|null
     */
    public function convert(string $contents, string $mime, ?string $prefer = null): ?array
    {
        if ($contents === '' || !extension_loaded('gd')) {
            return null;
        }

        $mime = strtolower(trim($mime));
        if (!in_array($mime, self::CONVERTIBLE, true)) {
            return null;
        }

        $prefer = $prefer === 'avif' || $prefer === 'webp' ? $prefer : null;

        // Already in the preferred (or default-best) modern format — skip re-encoding.
        if ($prefer === 'webp' && $mime === 'image/webp' && $this->supportsWebp()) {
            return null;
        }
        if ($prefer !== 'webp' && $mime === 'image/avif' && $this->supportsAvif()) {
            return null;
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            return null;
        }

        imagepalettetotruecolor($image);
        imagesavealpha($image, true);

        $width = imagesx($image);
        $height = imagesy($image);

        if ($prefer !== 'webp' && $this->supportsAvif()) {
            $encoded = $this->encodeAvif($image);
            if ($encoded !== null) {
                return [
                    'contents' => $encoded,
                    'mime' => 'image/avif',
                    'extension' => 'avif',
                    'width' => $width,
                    'height' => $height,
                ];
            }
        }

        if ($this->supportsWebp()) {
            // Already WebP — keep original.
            if ($mime === 'image/webp') {
                return null;
            }

            $encoded = $this->encodeWebp($image);
            if ($encoded !== null) {
                return [
                    'contents' => $encoded,
                    'mime' => 'image/webp',
                    'extension' => 'webp',
                    'width' => $width,
                    'height' => $height,
                ];
            }
        }

        return null;
    }

    public function supportsAvif(): bool
    {
        return function_exists('imageavif')
            && defined('IMG_AVIF')
            && (imagetypes() & IMG_AVIF) === IMG_AVIF;
    }

    public function supportsWebp(): bool
    {
        return function_exists('imagewebp')
            && defined('IMG_WEBP')
            && (imagetypes() & IMG_WEBP) === IMG_WEBP;
    }

    private function encodeAvif(\GdImage $image): ?string
    {
        $tmp = $this->tempFile('avif');
        if ($tmp === null) {
            return null;
        }

        try {
            if (!@imageavif($image, $tmp, $this->avifQuality)) {
                return null;
            }

            return $this->readTemp($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function encodeWebp(\GdImage $image): ?string
    {
        $tmp = $this->tempFile('webp');
        if ($tmp === null) {
            return null;
        }

        try {
            if (!@imagewebp($image, $tmp, $this->webpQuality)) {
                return null;
            }

            return $this->readTemp($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function tempFile(string $suffix): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'gereq_img_');
        if ($path === false) {
            return null;
        }

        $withExt = $path . '.' . $suffix;
        @rename($path, $withExt);

        return $withExt;
    }

    private function readTemp(string $path): ?string
    {
        if (!is_file($path) || filesize($path) === 0) {
            return null;
        }

        $bytes = file_get_contents($path);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }
}

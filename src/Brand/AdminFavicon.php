<?php

declare(strict_types=1);

namespace Gereq\Brand;

/**
 * Admin tab icon: uploaded brand favicon + red "A" badge.
 */
final class AdminFavicon
{
    public function __construct(private readonly string $basePath)
    {
    }

    /** @return array{body: string, mime: string, etag: string} */
    public function payload(): array
    {
        $source = $this->sourcePath();
        if ($source === null) {
            $body = $this->badgeOnlySvg();
            $mime = 'image/svg+xml';
        } else {
            $raster = $this->rasterize($source);
            if ($raster !== null) {
                $body = $raster;
                $mime = 'image/png';
            } else {
                $body = $this->svgOverlay($source);
                $mime = 'image/svg+xml';
            }
        }

        return [
            'body' => $body,
            'mime' => $mime,
            'etag' => md5($body),
        ];
    }

    public function version(): string
    {
        $source = $this->sourcePath();
        if ($source === null) {
            return '0';
        }

        return (string) (filemtime($source) ?: 0);
    }

    public function sourcePath(): ?string
    {
        $dir = $this->basePath . '/storage/uploads/brand';
        if (is_dir($dir)) {
            foreach (['favicon', 'logo'] as $base) {
                foreach (glob($dir . '/' . $base . '.*') ?: [] as $file) {
                    if (is_file($file)) {
                        return $file;
                    }
                }
            }
        }

        $fallback = $this->basePath . '/public/brand/icons/favicon.svg';

        return is_file($fallback) ? $fallback : null;
    }

    private function rasterize(string $path): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($src);
        }

        $size = 64;
        $canvas = imagecreatetruecolor($size, $size);
        if ($canvas === false) {
            return null;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size - 1, $size - 1, $clear);
        imagealphablending($canvas, true);

        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
        $this->stampBadge($canvas, $size);

        ob_start();
        imagesavealpha($canvas, true);
        imagepng($canvas, null, 6);
        $png = (string) ob_get_clean();

        return $png !== '' ? $png : null;
    }

    private function stampBadge(\GdImage $im, int $size): void
    {
        $cx = (int) round($size * 0.74);
        $cy = (int) round($size * 0.74);
        $outer = (int) round($size * 0.28);
        $inner = (int) round($size * 0.235);
        $white = imagecolorallocate($im, 255, 255, 255);
        $red = imagecolorallocate($im, 225, 29, 72);

        imagefilledellipse($im, $cx, $cy, $outer * 2, $outer * 2, $white);
        imagefilledellipse($im, $cx, $cy, $inner * 2, $inner * 2, $red);

        $s = $size / 64.0;
        $outerA = [
            $cx, (int) round($cy - 11 * $s),
            (int) round($cx - 8 * $s), (int) round($cy + 10 * $s),
            (int) round($cx - 4.5 * $s), (int) round($cy + 10 * $s),
            (int) round($cx - 3.1 * $s), (int) round($cy + 5.1 * $s),
            (int) round($cx + 3.1 * $s), (int) round($cy + 5.1 * $s),
            (int) round($cx + 4.5 * $s), (int) round($cy + 10 * $s),
            (int) round($cx + 8 * $s), (int) round($cy + 10 * $s),
        ];
        imagefilledpolygon($im, $outerA, $white);

        $hole = [
            $cx, (int) round($cy - 3.4 * $s),
            (int) round($cx - 2.5 * $s), (int) round($cy + 2.5 * $s),
            (int) round($cx + 2.5 * $s), (int) round($cy + 2.5 * $s),
        ];
        imagefilledpolygon($im, $hole, $red);
    }

    private function svgOverlay(string $path): string
    {
        $bytes = (string) file_get_contents($path);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'image/png',
        };

        return $this->wrapSvg('data:' . $mime . ';base64,' . base64_encode($bytes));
    }

    private function badgeOnlySvg(): string
    {
        return $this->wrapSvg(null);
    }

    private function wrapSvg(?string $imageHref): string
    {
        $image = $imageHref !== null
            ? '<image href="' . htmlspecialchars($imageHref, ENT_QUOTES) . '" width="32" height="32" preserveAspectRatio="xMidYMid meet"/>'
            : '<rect width="32" height="32" rx="9" fill="#dceacb"/>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . $image
            . '<circle cx="24" cy="24" r="8.6" fill="#fff"/>'
            . '<circle cx="24" cy="24" r="7.4" fill="#e11d48"/>'
            . '<path fill="#fff" fill-rule="evenodd" d="M24 17.55 19.9 28.15h2.02l.7-2.02h3.76l.7 2.02h2.02L24 17.55Zm0 3.5 1.16 3.32h-2.32L24 21.05Z"/>'
            . '</svg>';
    }
}

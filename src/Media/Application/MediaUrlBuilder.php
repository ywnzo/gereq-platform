<?php

declare(strict_types=1);

namespace Gereq\Media\Application;

use Gereq\Media\Domain\MediaAsset;

/**
 * Builds public media URLs with srcset/sizes for storefront and CMS JSON.
 * Width steps must stay in sync with {@see MediaFileDelivery}.
 */
final class MediaUrlBuilder
{
    /** @var list<int> */
    public const WIDTH_STEPS = [160, 320, 480, 640, 800, 960, 1200, 1600, 1920, 2400];

    public const LAYOUT_FULL = 'full';

    public const LAYOUT_CONSTRAINED = 'constrained';

    public const LAYOUT_THUMB = 'thumb';

    /**
     * @return array{
     *     id: string,
     *     src: string,
     *     url: string,
     *     srcset: string,
     *     sizes: string,
     *     width: int|null,
     *     height: int|null,
     *     alt: string|null,
     *     mime: string
     * }|null
     */
    public function forAsset(MediaAsset $asset, string $layout = self::LAYOUT_CONSTRAINED): ?array
    {
        if (!$asset->isImage()) {
            return null;
        }

        $layout = $this->normalizeLayout($layout);
        $steps = $this->srcsetWidths($asset->width(), $layout);
        if ($steps === []) {
            return null;
        }

        $defaultW = $this->defaultSrcWidth($steps, $layout);
        $base = '/media/' . $asset->id() . '/file';
        $src = $base . '?w=' . $defaultW;

        $parts = [];
        foreach ($steps as $width) {
            $parts[] = $base . '?w=' . $width . ' ' . $width . 'w';
        }

        return [
            'id' => $asset->id(),
            'src' => $src,
            'url' => $src,
            'srcset' => implode(', ', $parts),
            'sizes' => $this->sizesFor($layout, $defaultW),
            'width' => $asset->width(),
            'height' => $asset->height(),
            'alt' => $asset->alt(),
            'mime' => $asset->mime(),
        ];
    }

    /**
     * @return list<int>
     */
    public function srcsetWidths(?int $nativeWidth, string $layout = self::LAYOUT_CONSTRAINED): array
    {
        $layout = $this->normalizeLayout($layout);
        $cap = $this->layoutCap($layout);
        if ($nativeWidth !== null && $nativeWidth > 0) {
            $cap = min($cap, $nativeWidth);
        }

        $steps = [];
        foreach (self::WIDTH_STEPS as $step) {
            if ($step <= $cap) {
                $steps[] = $step;
            }
        }

        // Tiny assets: still expose one candidate at the nearest step ≤ native (or smallest step).
        if ($steps === [] && $nativeWidth !== null && $nativeWidth > 0) {
            return [self::WIDTH_STEPS[0]];
        }

        if ($steps === []) {
            return self::WIDTH_STEPS;
        }

        return $steps;
    }

    private function defaultSrcWidth(array $steps, string $layout): int
    {
        $preferred = match ($layout) {
            self::LAYOUT_THUMB => 320,
            self::LAYOUT_FULL => 1600,
            default => 960,
        };

        $chosen = $steps[0];
        foreach ($steps as $step) {
            $chosen = $step;
            if ($step >= $preferred) {
                break;
            }
        }

        return $chosen;
    }

    private function sizesFor(string $layout, int $defaultW): string
    {
        return match ($layout) {
            self::LAYOUT_FULL => '100vw',
            self::LAYOUT_THUMB => '(max-width: 768px) 50vw, ' . $defaultW . 'px',
            default => '(max-width: 768px) 100vw, ' . $defaultW . 'px',
        };
    }

    private function layoutCap(string $layout): int
    {
        return match ($layout) {
            self::LAYOUT_THUMB => 640,
            self::LAYOUT_FULL => 2400,
            default => 1920,
        };
    }

    private function normalizeLayout(string $layout): string
    {
        return match ($layout) {
            self::LAYOUT_FULL, self::LAYOUT_THUMB, self::LAYOUT_CONSTRAINED => $layout,
            default => self::LAYOUT_CONSTRAINED,
        };
    }
}

<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\Health;

/**
 * Inspects CMS public page payloads for responsive image fields.
 */
final class CmsMediaPagespeed
{
    /**
     * @param list<array<string, mixed>> $blocks
     * @return array{images: int, with_srcset: int, missing_srcset: int, missing_dimensions: int}
     */
    public static function summarizeBlocks(array $blocks): array
    {
        $images = 0;
        $withSrcset = 0;
        $missingSrcset = 0;
        $missingDimensions = 0;

        $walk = function (array $list) use (&$walk, &$images, &$withSrcset, &$missingSrcset, &$missingDimensions): void {
            foreach ($list as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $type = (string) ($block['type'] ?? '');
                $payload = is_array($block['payload'] ?? null) ? $block['payload'] : [];

                if ($type === 'image') {
                    $images++;
                    self::tallyImage($payload, $withSrcset, $missingSrcset, $missingDimensions);
                } elseif ($type === 'gallery') {
                    $galleryImages = is_array($payload['images'] ?? null) ? $payload['images'] : [];
                    foreach ($galleryImages as $img) {
                        if (!is_array($img)) {
                            continue;
                        }
                        $images++;
                        self::tallyImage($img, $withSrcset, $missingSrcset, $missingDimensions);
                    }
                } elseif ($type === 'layout') {
                    $kids = is_array($payload['children'] ?? null) ? $payload['children'] : [];
                    /** @var list<array<string, mixed>> $kids */
                    $walk($kids);
                }
            }
        };

        $walk($blocks);

        return [
            'images' => $images,
            'with_srcset' => $withSrcset,
            'missing_srcset' => $missingSrcset,
            'missing_dimensions' => $missingDimensions,
        ];
    }

    /** @param array<string, mixed> $image */
    private static function tallyImage(
        array $image,
        int &$withSrcset,
        int &$missingSrcset,
        int &$missingDimensions,
    ): void {
        $src = trim((string) ($image['src'] ?? $image['url'] ?? ''));
        if ($src === '') {
            return;
        }
        $srcset = trim((string) ($image['srcset'] ?? ''));
        if ($srcset !== '' || str_contains($src, '?w=')) {
            $withSrcset++;
        } else {
            $missingSrcset++;
        }
        $w = $image['width'] ?? null;
        $h = $image['height'] ?? null;
        if (!is_numeric($w) || !is_numeric($h) || (int) $w <= 0 || (int) $h <= 0) {
            $missingDimensions++;
        }
    }
}

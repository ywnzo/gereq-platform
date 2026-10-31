<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

/** Persist per-photo layout separately from provider-generated media URLs. */
final class GalleryImageSettingsNormalizer
{
    private const RATIOS = ['', '1/1', '4/3', '3/2', '16/9', '3/4', '2/3', '9/16'];

    public static function normalize(mixed $raw): string
    {
        if (is_string($raw)) {
            $raw = strlen($raw) <= 1048576 ? json_decode($raw, true) : null;
        }
        if (!is_array($raw)) {
            return '{}';
        }
        $items = [];
        foreach (array_slice($raw, 0, 1000, true) as $key => $item) {
            $key = (string) $key;
            if ($key === '' || strlen($key) > 2048 || !is_array($item)) {
                continue;
            }
            $ratio = self::text($item['ratio'] ?? '');
            $items[$key] = [
                'span' => self::bounded($item['span'] ?? 1, 1, 3),
                'ratio' => in_array($ratio, self::RATIOS, true) ? $ratio : '',
                'height' => self::bounded($item['height'] ?? 0, 0, 4096),
                'group' => mb_substr(trim(self::text($item['group'] ?? '')), 0, 64),
                'column' => self::bounded($item['column'] ?? 0, 0, 3),
                'fit' => ($item['fit'] ?? '') === 'cover' ? 'cover' : 'contain',
            ];
        }

        return (string) json_encode((object) $items, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function bounded(mixed $value, int $minimum, int $maximum): int
    {
        return is_numeric($value) ? (int) round(max($minimum, min($maximum, (float) $value))) : $minimum;
    }
}

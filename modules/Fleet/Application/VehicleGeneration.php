<?php

declare(strict_types=1);

namespace Modules\Fleet\Application;

/**
 * Odhad generace modelu podle roku (I / II / III …).
 * Ruční hodnota v tech_json.generation má přednost.
 */
final class VehicleGeneration
{
    /**
     * Inclusive year ranges, ordered oldest → newest.
     * Overlaps: first matching range wins (prefer earlier bound).
     *
     * @var array<string, list<array{from: int, to: int, code: string}>>
     */
    private const MAP = [
        'octavia' => [
            ['from' => 1996, 'to' => 2003, 'code' => 'I'],
            ['from' => 2004, 'to' => 2012, 'code' => 'II'],
            ['from' => 2013, 'to' => 2019, 'code' => 'III'],
            ['from' => 2020, 'to' => 2099, 'code' => 'IV'],
        ],
        'fabia' => [
            ['from' => 1999, 'to' => 2006, 'code' => 'I'],
            ['from' => 2007, 'to' => 2014, 'code' => 'II'],
            ['from' => 2015, 'to' => 2021, 'code' => 'III'],
            ['from' => 2022, 'to' => 2099, 'code' => 'IV'],
        ],
        'superb' => [
            ['from' => 2001, 'to' => 2008, 'code' => 'I'],
            ['from' => 2008, 'to' => 2015, 'code' => 'II'],
            ['from' => 2015, 'to' => 2023, 'code' => 'III'],
            ['from' => 2024, 'to' => 2099, 'code' => 'IV'],
        ],
        'rapid' => [
            ['from' => 2012, 'to' => 2019, 'code' => 'I'],
        ],
        'roomster' => [
            ['from' => 2006, 'to' => 2015, 'code' => 'I'],
        ],
        'yeti' => [
            ['from' => 2009, 'to' => 2017, 'code' => 'I'],
        ],
        'karoq' => [
            ['from' => 2017, 'to' => 2099, 'code' => 'I'],
        ],
        'kodiaq' => [
            ['from' => 2016, 'to' => 2099, 'code' => 'I'],
        ],
        'kamiq' => [
            ['from' => 2019, 'to' => 2099, 'code' => 'I'],
        ],
        'scala' => [
            ['from' => 2019, 'to' => 2099, 'code' => 'I'],
        ],
        'enyaq' => [
            ['from' => 2020, 'to' => 2099, 'code' => 'I'],
        ],
        'golf' => [
            ['from' => 1997, 'to' => 2003, 'code' => 'IV'],
            ['from' => 2003, 'to' => 2008, 'code' => 'V'],
            ['from' => 2008, 'to' => 2012, 'code' => 'VI'],
            ['from' => 2012, 'to' => 2019, 'code' => 'VII'],
            ['from' => 2020, 'to' => 2099, 'code' => 'VIII'],
        ],
        'passat' => [
            ['from' => 1996, 'to' => 2005, 'code' => 'B5'],
            ['from' => 2005, 'to' => 2010, 'code' => 'B6'],
            ['from' => 2010, 'to' => 2014, 'code' => 'B7'],
            ['from' => 2014, 'to' => 2023, 'code' => 'B8'],
            ['from' => 2024, 'to' => 2099, 'code' => 'B9'],
        ],
        'caddy' => [
            ['from' => 2004, 'to' => 2020, 'code' => 'III'],
            ['from' => 2020, 'to' => 2099, 'code' => 'V'],
        ],
        'transporter' => [
            ['from' => 2003, 'to' => 2015, 'code' => 'T5'],
            ['from' => 2015, 'to' => 2099, 'code' => 'T6'],
        ],
    ];

    public static function resolve(?string $brand, ?string $model, ?int $year): ?string
    {
        if ($model === null || trim($model) === '') {
            return null;
        }
        $fromName = self::extractFromName($model);
        if ($fromName !== null) {
            return $fromName;
        }
        if ($year === null || $year < 1980 || $year > 2100) {
            return null;
        }
        $key = self::modelKey($model);
        if ($key === null || !isset(self::MAP[$key])) {
            return null;
        }
        foreach (self::MAP[$key] as $range) {
            if ($year >= $range['from'] && $year <= $range['to']) {
                return $range['code'];
            }
        }

        return null;
    }

    public static function formatModel(?string $model, ?string $generation): string
    {
        $model = trim((string) $model);
        if ($model === '') {
            return '';
        }
        $generation = trim((string) $generation);
        if ($generation === '') {
            return $model;
        }
        if (preg_match('/(^|[\s\-])' . preg_quote($generation, '/') . '($|[\s\-])/ui', $model) === 1) {
            return $model;
        }

        return $model . ' ' . $generation;
    }

    public static function extractFromName(string $model): ?string
    {
        if (preg_match('/\b([IVX]{1,4})\b/u', $model, $m) === 1) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\b(B[5-9]|T[456]|Mk\s*[IVX0-9]+)\b/ui', $model, $m) === 1) {
            return strtoupper(str_replace(' ', '', $m[1]));
        }

        return null;
    }

    private static function modelKey(string $model): ?string
    {
        $normalized = mb_strtolower(trim($model));
        $normalized = strtr($normalized, ['á' => 'a', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'č' => 'c', 'ď' => 'd', 'ň' => 'n', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ž' => 'z']);
        $normalized = preg_replace('/[^a-z0-9\s]/u', ' ', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        foreach (array_keys(self::MAP) as $key) {
            if ($normalized === $key || str_starts_with($normalized, $key . ' ') || str_contains($normalized, ' ' . $key . ' ') || str_ends_with($normalized, ' ' . $key)) {
                return $key;
            }
        }

        return null;
    }
}

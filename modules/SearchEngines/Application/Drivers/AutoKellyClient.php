<?php

declare(strict_types=1);

namespace Modules\SearchEngines\Application\Drivers;

use Modules\SearchEngines\Application\HttpFetch;

/**
 * AutoKelly / LKQ (autokelly.cz) — JSON Search2 + retail ceny.
 */
final class AutoKellyClient
{
    private const BASE = 'https://www.autokelly.cz';

    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string, has_more: bool}
     */
    public function search(string $query, int $limit = 12): array
    {
        $code = HttpFetch::normalizePartQuery($query);
        if ($code === '') {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.query_required', 'has_more' => false];
        }
        // UI/lupa posílá „OEM {kód}“; Search2 API bere jen samotné číslo.
        $query = $code;

        $cookie = sys_get_temp_dir() . '/gereq-autokelly-cookies.txt';
        $warm = HttpFetch::get(self::BASE . '/', [
            'Accept: text/html,application/xhtml+xml',
            'Referer: ' . self::BASE . '/',
        ], 15, $cookie);
        if (!$warm['ok']) {
            return ['ok' => false, 'items' => [], 'error' => $warm['error'], 'has_more' => false];
        }

        $searchUrl = self::searchUrl($code);
        HttpFetch::get($searchUrl, ['Accept: text/html,application/xhtml+xml', 'Referer: ' . self::BASE . '/'], 15, $cookie);

        $res = HttpFetch::postJson(
            self::BASE . '/Search2/Product/Data',
            ['searchText' => $query, 'pathIds' => null, 'exclude' => null],
            [
                'Origin: ' . self::BASE,
                'Referer: ' . $searchUrl,
            ],
            20,
            $cookie,
        );
        if (!$res['ok']) {
            return ['ok' => false, 'items' => [], 'error' => $res['error'], 'has_more' => false];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($res['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.invalid', 'has_more' => false];
        }

        $products = $decoded['products'] ?? null;
        if (!is_array($products) || $products === []) {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.no_results', 'has_more' => false];
        }

        $ids = [];
        foreach ($products as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['Id'] ?? ''));
            if ($id !== '') {
                $ids[] = (int) $id;
            }
        }
        $prices = $this->retailPrices($ids, $cookie, $searchUrl);

        $items = [];
        foreach ($products as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? ''));
            $code = trim((string) ($row['Code'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($code !== '') {
                $name = $name . ' / ' . $code;
            }
            $id = trim((string) ($row['Id'] ?? ''));
            $link = trim((string) ($row['Link'] ?? ''));
            if ($link === '' && $id !== '') {
                $slug = preg_replace('/\s+/u', '-', $code !== '' ? $code : $id) ?? $id;
                $link = '/Product/' . rawurlencode($slug) . '/' . rawurlencode($id);
            }
            $href = $link === '' ? self::searchUrl($query) : (str_starts_with($link, 'http') ? $link : (self::BASE . $link));
            $image = trim((string) ($row['UrlImage'] ?? $row['Image'] ?? ''));
            if ($image === '') {
                $image = null;
            }
            $pid = $id !== '' ? (int) $id : 0;
            $minPrice = $pid > 0 ? ($prices[$pid] ?? null) : null;

            $items[] = [
                'id' => 'autokelly-' . ($id !== '' ? $id : (string) ($i + 1)),
                'name' => $name,
                'url' => $href,
                'min_price' => $minPrice,
                'currency' => 'CZK',
                'shop' => 'AutoKelly',
                'source' => 'autokelly',
                'image' => $image,
                'description' => $code !== '' ? $code : null,
                'brand' => null,
                'parameters' => $code !== '' ? ['code' => $code] : [],
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'ok' => $items !== [],
            'items' => $items,
            'error' => $items === [] ? 'searchengines.error.no_results' : null,
            'has_more' => false,
        ];
    }

    public static function searchUrl(string $query): string
    {
        $code = HttpFetch::normalizePartQuery($query);
        // Odkaz v UI: lexikon „OEM {kód}“; API volání používá samotný kód.
        $text = self::oemSearchText($code);

        return self::BASE . '/Search/ResultList?searchText=' . rawurlencode($text !== '' ? $text : $code);
    }

    /** Veřejný tvar dotazu pro AutoKelly: „OEM {katalogové číslo}“. */
    public static function oemSearchText(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }
        if (preg_match('/^OEM\s+/iu', $code) === 1) {
            return trim((string) preg_replace('/^OEM\s+/iu', 'OEM ', $code));
        }

        return 'OEM ' . $code;
    }

    /**
     * @param list<int> $ids
     * @return array<int, float>
     */
    private function retailPrices(array $ids, string $cookie, string $referer): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $res = HttpFetch::postJson(
            self::BASE . '/RetailPrice/Data',
            ['productIds' => $ids],
            [
                'Origin: ' . self::BASE,
                'Referer: ' . $referer,
            ],
            15,
            $cookie,
        );
        if (!$res['ok']) {
            return [];
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($res['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $content = $decoded['Content'] ?? null;
        if (!is_array($content)) {
            return [];
        }
        $out = [];
        foreach ($content as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = (int) ($row['ProductId'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $vat = $row['PriceVatValue'] ?? null;
            $net = $row['PriceValue'] ?? null;
            if (is_numeric($vat) && (float) $vat > 0) {
                $out[$pid] = round((float) $vat, 2);
            } elseif (is_numeric($net) && (float) $net > 0) {
                $out[$pid] = round((float) $net, 2);
            }
        }

        return $out;
    }
}

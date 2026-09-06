<?php

declare(strict_types=1);

namespace Modules\SearchEngines\Application\Drivers;

use Modules\SearchEngines\Application\HttpFetch;

/**
 * Seznam Zboží.cz — scrape __NEXT_DATA__ (veřejné hledání).
 */
final class SeznamZboziClient
{
    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string, has_more: bool}
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.query_required', 'has_more' => false];
        }
        $url = self::searchUrl($query);
        $res = HttpFetch::get($url);
        if (!$res['ok']) {
            return ['ok' => false, 'items' => [], 'error' => $res['error'], 'has_more' => false];
        }
        if (!preg_match('/__NEXT_DATA__"[^>]*>(\{.+?\})<\/script>/s', $res['body'], $m)) {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.invalid', 'has_more' => false];
        }
        try {
            /** @var array<string, mixed> $json */
            $json = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.invalid', 'has_more' => false];
        }

        $products = $json['props']['pageProps']['data']['productsResults']['products'] ?? null;
        if (!is_array($products)) {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.no_results', 'has_more' => false];
        }

        $items = [];
        foreach ($products as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $path = (string) ($row['url'] ?? '');
            $href = $path !== ''
                ? (str_starts_with($path, 'http') ? $path : ('https://www.zbozi.cz' . $path))
                : self::searchUrl($name);
            $minRaw = isset($row['minPrice']) && is_numeric($row['minPrice']) ? (float) $row['minPrice'] : null;
            $maxRaw = isset($row['maxPrice']) && is_numeric($row['maxPrice']) ? (float) $row['maxPrice'] : null;
            // Zboží vrací ceny v haléřích
            $minPrice = $minRaw !== null && $minRaw > 0 ? round($minRaw / 100, 2) : null;
            $maxPrice = $maxRaw !== null && $maxRaw > 0 ? round($maxRaw / 100, 2) : null;
            $params = [];
            if (isset($row['parameters']) && is_array($row['parameters'])) {
                foreach ($row['parameters'] as $p) {
                    $p = trim((string) $p);
                    if ($p !== '') {
                        $params[] = $p;
                    }
                }
            }
            $items[] = [
                'id' => 'seznam-' . (string) ($row['id'] ?? count($items)),
                'name' => $name,
                'url' => $href,
                'min_price' => $minPrice,
                'max_price' => $maxPrice,
                'currency' => 'CZK',
                'shop' => 'Zboží.cz',
                'shop_count' => isset($row['shopCount']) ? (int) $row['shopCount'] : null,
                'source' => 'seznam',
                'image' => isset($row['image']) && is_string($row['image']) && $row['image'] !== '' ? $row['image'] : null,
                'description' => isset($row['description']) && is_string($row['description']) ? mb_substr(trim($row['description']), 0, 420) : null,
                'brand' => isset($row['brandName']) && is_string($row['brandName']) && $row['brandName'] !== '' ? $row['brandName'] : null,
                'parameters' => $params,
                'rating' => isset($row['rating']) && is_numeric($row['rating']) ? ((float) $row['rating']) / 20 : null,
                'rating_count' => (int) ($row['ratingCount'] ?? 0),
                'availability' => isset($row['availability']) && is_string($row['availability']) ? $row['availability'] : null,
                'category_root' => isset($row['rootCategoryId']) ? (int) $row['rootCategoryId'] : null,
                'category_parent' => isset($row['parentCategoryId']) ? (int) $row['parentCategoryId'] : null,
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'ok' => $items !== [],
            'items' => $items,
            'error' => $items === [] ? 'searchengines.error.no_results' : null,
            'has_more' => count($products) > count($items),
        ];
    }

    public static function searchUrl(string $query): string
    {
        return 'https://www.zbozi.cz/hledej/?q=' . rawurlencode(trim($query));
    }

    /**
     * @return array{ok: bool, offers: list<array<string, mixed>>, error: ?string}
     */
    public function offers(string $productUrl): array
    {
        $productUrl = trim($productUrl);
        if ($productUrl === '') {
            return ['ok' => false, 'offers' => [], 'error' => 'searchengines.error.query_required'];
        }
        if (!str_starts_with($productUrl, 'http')) {
            $productUrl = 'https://www.zbozi.cz' . (str_starts_with($productUrl, '/') ? $productUrl : '/' . $productUrl);
        }
        $res = HttpFetch::get($productUrl, [], 20);
        if (!$res['ok']) {
            return ['ok' => false, 'offers' => [], 'error' => $res['error']];
        }
        if (!preg_match('/__NEXT_DATA__"[^>]*>(\{.+?\})<\/script>/s', $res['body'], $m)) {
            return ['ok' => false, 'offers' => [], 'error' => 'searchengines.error.invalid'];
        }
        try {
            /** @var array<string, mixed> $json */
            $json = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'offers' => [], 'error' => 'searchengines.error.invalid'];
        }
        $items = $json['props']['pageProps']['data']['offers']['items'] ?? null;
        if (!is_array($items)) {
            return ['ok' => false, 'offers' => [], 'error' => 'searchengines.error.no_results'];
        }
        $offers = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $shop = '';
            if (isset($row['shop']) && is_array($row['shop'])) {
                $shop = trim((string) ($row['shop']['name'] ?? ''));
            }
            if ($shop === '' && isset($row['seller']) && is_array($row['seller'])) {
                $shop = trim((string) ($row['seller']['name'] ?? ''));
            }
            if ($shop === '') {
                continue;
            }
            $priceRaw = isset($row['price']) && is_numeric($row['price']) ? (float) $row['price'] : null;
            $price = $priceRaw !== null && $priceRaw > 0 ? round($priceRaw / 100, 2) : ($priceRaw === 0.0 ? 0.0 : null);
            $deliveryRaw = isset($row['minPriceDelivery']) && is_numeric($row['minPriceDelivery']) ? (float) $row['minPriceDelivery'] : null;
            $freeShipping = $deliveryRaw !== null && $deliveryRaw <= 0;
            $path = (string) ($row['url'] ?? '');
            $href = $path !== ''
                ? (str_starts_with($path, 'http') ? $path : ('https://www.zbozi.cz' . $path))
                : $productUrl;
            $offers[] = [
                'shop' => $shop,
                'price' => $price,
                'currency' => 'CZK',
                'availability' => isset($row['availability']) && is_string($row['availability']) ? $row['availability'] : null,
                'shipping' => $freeShipping ? 'Doprava zdarma' : null,
                'free_shipping' => $freeShipping,
                'url' => $href,
            ];
        }

        return [
            'ok' => $offers !== [],
            'offers' => $offers,
            'error' => $offers === [] ? 'searchengines.error.no_results' : null,
        ];
    }
}

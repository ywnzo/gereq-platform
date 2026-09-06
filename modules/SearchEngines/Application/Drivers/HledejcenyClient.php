<?php

declare(strict_types=1);

namespace Modules\SearchEngines\Application\Drivers;

use Modules\SearchEngines\Application\HttpFetch;

/**
 * Hledejceny.cz — HTML scrape výsledků hledání.
 */
final class HledejcenyClient
{
    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string, has_more: bool}
     */
    public function search(string $query, int $limit = 10): array
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

        $items = [];
        $parts = preg_split('/<article class="product-item-horizontal"/', $res['body']) ?: [];
        array_shift($parts);
        foreach ($parts as $i => $block) {
            if (!preg_match('/product-item-horizontal__link" href="([^"]+)"[^>]*>\s*([^<]+)/u', $block, $am)) {
                continue;
            }
            $path = html_entity_decode($am[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $name = trim(html_entity_decode($am[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($name === '') {
                continue;
            }
            $minPrice = null;
            if (preg_match('/product-item-horizontal__price[^>]*>[\s\S]*?([\d\s]+)\s*(?:-|–)?\s*([\d\s]+)?\s*Kč/u', $block, $pm)) {
                $minPrice = HttpFetch::parseCzPrice($pm[1]);
            }
            $image = null;
            if (preg_match('/product-item-horizontal__image[^>]*src="([^"]+)"/u', $block, $im)
                || preg_match('/<img[^>]+src="([^"]+)"[^>]*class="[^"]*product-item-horizontal__image/u', $block, $im)) {
                $image = html_entity_decode($im[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $href = str_starts_with($path, 'http') ? $path : ('https://www.hledejceny.cz' . $path);
            $items[] = [
                'id' => 'hledejceny-' . ($i + 1),
                'name' => $name,
                'url' => $href,
                'min_price' => $minPrice,
                'currency' => 'CZK',
                'shop' => 'Hledejceny',
                'source' => 'hledejceny',
                'image' => $image,
                'description' => null,
                'brand' => null,
                'parameters' => [],
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
        return 'https://www.hledejceny.cz/hledat?search=' . rawurlencode(trim($query));
    }
}

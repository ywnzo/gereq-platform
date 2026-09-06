<?php

declare(strict_types=1);

namespace Modules\SearchEngines\Application\Drivers;

use Modules\SearchEngines\Application\HttpFetch;

/**
 * Autodílypro.cz — HTML scrape /kategoria/vyhladavanie?q=…
 */
final class AutodilyProClient
{
    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string, has_more: bool}
     */
    public function search(string $query, int $limit = 12): array
    {
        $query = HttpFetch::normalizePartQuery($query);
        if ($query === '') {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.query_required', 'has_more' => false];
        }
        $res = HttpFetch::get(self::searchUrl($query), [], 18);
        if (!$res['ok']) {
            return ['ok' => false, 'items' => [], 'error' => $res['error'], 'has_more' => false];
        }

        $items = [];
        $parts = preg_split('/<div class="product clearfix">/u', $res['body']) ?: [];
        array_shift($parts);
        foreach ($parts as $i => $block) {
            $block = substr($block, 0, 10000);
            if (preg_match('/<h2>\s*<a href="([^"]+)"[^>]*>\s*([\s\S]*?)\s*<\/a>/u', $block, $am) !== 1) {
                continue;
            }
            $href = html_entity_decode(trim($am[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $name = trim(html_entity_decode(strip_tags($am[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
            if ($name === '' || $href === '') {
                continue;
            }
            // odřízni tracking query z detailu
            $href = preg_replace('/\?.*$/u', '', $href) ?? $href;

            $minPrice = null;
            if (preg_match('/price-value[^>]*>\s*([^<]+?)\s*Kč/u', $block, $pm) === 1
                || preg_match('/price-value[^>]*>\s*([^<]+)/u', $block, $pm) === 1
            ) {
                $minPrice = HttpFetch::parseCzPrice($pm[1]);
            }

            $image = null;
            if (preg_match('/<div class="image"[^>]*>\s*<a[^>]*>\s*<img[^>]+src="([^"]+)"/u', $block, $im) === 1
                || preg_match('/<img[^>]+src="([^"]+)"[^>]*alt="/u', $block, $im) === 1
            ) {
                $image = html_entity_decode(trim($im[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            $items[] = [
                'id' => 'autodilypro-' . ($i + 1),
                'name' => $name,
                'url' => $href,
                'min_price' => $minPrice,
                'currency' => 'CZK',
                'shop' => 'Autodílypro',
                'source' => 'autodilypro',
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
        $query = HttpFetch::normalizePartQuery($query);

        return 'https://www.autodilypro.cz/kategoria/vyhladavanie?q=' . rawurlencode($query);
    }
}

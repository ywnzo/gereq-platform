<?php

declare(strict_types=1);

namespace Modules\SearchEngines\Application\Drivers;

use Modules\SearchEngines\Application\HttpFetch;

/**
 * Mojekolo.cz — HTML scrape /search/?search=…
 */
final class MojekoloClient
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
        $parts = preg_split('/list-products__item\s/', $res['body']) ?: [];
        array_shift($parts);
        foreach ($parts as $i => $block) {
            $block = substr($block, 0, 12000);
            if (!preg_match('/product__list__header">\s*<a href="([^"]+)" title="([^"]+)"/u', $block, $am)) {
                continue;
            }
            $path = $am[1];
            $name = trim(html_entity_decode($am[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($name === '' || str_contains($path, '/magazin/')) {
                continue;
            }
            $minPrice = null;
            if (preg_match('/price--now[^>]*>\s*(?:od\s*)?([\d\s]+)\s*Kč/u', $block, $pm)) {
                $minPrice = HttpFetch::parseCzPrice($pm[1]);
            }
            $image = null;
            if (preg_match('/<img[^>]+src="([^"]+)"[^>]*class="[^"]*product__list__photo__img/u', $block, $im)
                || preg_match('/class="[^"]*product__list__photo__img[^"]*"[^>]*src="([^"]+)"/u', $block, $im)
                || preg_match('/<img[^>]+src="([^"]+upload\/photo[^"]+)"/u', $block, $im)) {
                $src = html_entity_decode($im[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $image = str_starts_with($src, 'http') ? $src : ('https://www.mojekolo.cz' . $src);
            }
            $href = str_starts_with($path, 'http') ? $path : ('https://www.mojekolo.cz' . $path);
            $items[] = [
                'id' => 'mojekolo-' . ($i + 1),
                'name' => $name,
                'url' => $href,
                'min_price' => $minPrice,
                'currency' => 'CZK',
                'shop' => 'Mojekolo',
                'source' => 'mojekolo',
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
        return 'https://www.mojekolo.cz/search/?search=' . rawurlencode(trim($query));
    }
}

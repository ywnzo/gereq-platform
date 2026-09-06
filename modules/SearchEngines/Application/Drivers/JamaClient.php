<?php

declare(strict_types=1);

namespace Modules\SearchEngines\Application\Drivers;

use Modules\SearchEngines\Application\HttpFetch;

/**
 * Autodíly Jama (autodily-jama.cz) — HTML scrape /hledani/?search=…
 */
final class JamaClient
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
        if (str_contains($res['body'], 'Making sure you') || str_contains($res['body'], 'BotStopper')) {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.request_failed', 'has_more' => false];
        }

        $items = [];
        $chunks = preg_split('/(?=<div[^>]*class="[^"]*product-card-insider)/u', $res['body']) ?: [];
        foreach ($chunks as $i => $chunk) {
            $chunk = substr($chunk, 0, 14000);
            if (!str_contains($chunk, 'product-card-title-link')) {
                continue;
            }
            $path = '';
            $name = '';
            if (preg_match('/product-card-title-link"[^>]*href="([^"]+)"[^>]*title="([^"]*)"/u', $chunk, $am) === 1) {
                $path = html_entity_decode(trim($am[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $name = trim(html_entity_decode($am[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            } elseif (preg_match('/product-card-title-link"[^>]*href="([^"]+)"[^>]*>\s*([^<]+)/u', $chunk, $am) === 1) {
                $path = html_entity_decode(trim($am[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $name = trim(html_entity_decode($am[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
            if ($name === '' || $path === '') {
                continue;
            }

            $minPrice = null;
            if (preg_match("/priceValue[^>]*>\s*([^<]+)/u", $chunk, $pm) === 1
                || preg_match('/class=[\'"]price[^\'"]*[\'"][^>]*>.*?([\d&\w\s\xc2\xa0.;,]+)\s*(?:<[^>]+>\s*)*Kč/su', $chunk, $pm) === 1
            ) {
                $minPrice = HttpFetch::parseCzPrice($pm[1]);
            }

            $image = null;
            if (preg_match('/src="(https?:\/\/image\.systemit\.cz[^"]+)"/i', $chunk, $im) === 1
                || preg_match('/product-card-image[^>]*src="([^"]+)"/i', $chunk, $im) === 1
            ) {
                $src = html_entity_decode(trim($im[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $image = str_starts_with($src, 'http') ? $src : ('https://www.autodily-jama.cz' . $src);
            }

            $oem = null;
            if (preg_match('/Katalogový kód\s*<strong>([^<]+)</u', $chunk, $cm) === 1) {
                $oem = trim(html_entity_decode($cm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            $href = str_starts_with($path, 'http') ? $path : ('https://www.autodily-jama.cz' . $path);
            $items[] = [
                'id' => 'jama-' . ($i + 1),
                'name' => $name,
                'url' => $href,
                'min_price' => $minPrice,
                'currency' => 'CZK',
                'shop' => 'Autodíly Jama',
                'source' => 'jama',
                'image' => $image,
                'description' => $oem !== null && $oem !== '' ? ('OEM ' . $oem) : null,
                'brand' => null,
                'parameters' => $oem !== null && $oem !== '' ? ['oem' => $oem] : [],
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

        return 'https://www.autodily-jama.cz/hledani/?search=' . rawurlencode($query);
    }
}

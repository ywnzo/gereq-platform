<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application\Provider;

use Gereq\Setting\Settings;
use Modules\PartsCatalog\Application\CatalogApiCacheStore;
use Modules\PartsCatalog\Application\CatalogCallLogger;
use Modules\PartsCatalog\Application\Dto\CatalogArticle;
use Modules\PartsCatalog\Application\Dto\CatalogNode;
use Modules\PartsCatalog\Application\Dto\CatalogVehicleOption;
use Modules\PartsCatalog\Application\HttpJsonClient;
use Modules\PartsCatalog\Application\PartsCatalogImageService;

/**
 * autodily-jama.cz — TecDoc SSR + POST /actions/ajax/getProducts.php
 *
 * Funguje z Dockeru s Chrome UA (Anubis na JS občas; HTML katalog + AJAX produkty OK).
 * mbautogroupdily.cz je celé za Anubisem — sem ne.
 */
final class JamaCatalogProvider implements CatalogProviderInterface
{
    private const DEFAULT_BASE = 'https://www.autodily-jama.cz';

    private const MAX_VEHICLES = 60;

    private const MAX_PRODUCTS = 120;

    /** @var array<string, string> brand needle → URL segment under /autodily/ */
    private const BRAND_PATHS = [
        'skoda' => 'skoda',
        'škoda' => 'skoda',
        'vw' => 'nahradni-dily-vw',
        'volkswagen' => 'nahradni-dily-vw',
        'audi' => 'nahradni-dily-audi',
        'seat' => 'nahradni-dily-seat',
        'ford' => 'nahradni-dily-ford',
        'opel' => 'nahradni-dily-opel',
        'renault' => 'nahradni-dily-renault',
        'peugeot' => 'nahradni-dily-peugeot',
        'citroen' => 'nahradni-dily-citroen',
        'citroën' => 'nahradni-dily-citroen',
        'fiat' => 'nahradni-dily-fiat',
        'mercedes' => 'nahradni-dily-mercedes',
        'mercedes-benz' => 'nahradni-dily-mercedes',
        'bmw' => 'nahradni-dily-bmw',
        'hyundai' => 'nahradni-dily-hyundai',
        'kia' => 'nahradni-dily-kia',
        'toyota' => 'nahradni-dily-toyota',
    ];

    private ?string $softMessage = null;

    private ?string $cookieFile = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpJsonClient $http,
        private readonly CatalogApiCacheStore $apiCache,
        private readonly PartsCatalogImageService $images,
    ) {
    }

    public function id(): string
    {
        return 'jama';
    }

    public function isConfigured(): bool
    {
        return $this->base() !== '';
    }

    public function softError(): ?string
    {
        return $this->softMessage;
    }

    /**
     * Všechny značky z /autodily/ (bez hardcoded mapy).
     *
     * @return list<array{slug: string, name: string}>
     */
    public function listAllBrands(): array
    {
        CatalogCallLogger::log('jama.brands.in', []);
        $html = $this->fetchHtml($this->base() . '/autodily/');
        if ($html === null) {
            CatalogCallLogger::log('jama.brands.out', ['count' => 0, 'error' => $this->softMessage]);

            return [];
        }

        $out = [];
        if (preg_match_all('/href="(\/autodily\/([^"\/]+)\/)"/i', $html, $m)) {
            foreach ($m[2] as $slug) {
                $slug = rawurldecode((string) $slug);
                if ($slug === '' || str_contains($slug, '.')) {
                    continue;
                }
                $out[$slug] = [
                    'slug' => $slug,
                    'name' => $this->brandDisplayName($slug),
                ];
            }
        }

        $list = array_values($out);
        usort($list, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        CatalogCallLogger::log('jama.brands.out', ['count' => count($list)]);

        return $list;
    }

    /**
     * @return list<array{slug: string, name: string}>
     */
    public function listAllModels(string $brandPath): array
    {
        $brandPath = trim($brandPath, '/');
        CatalogCallLogger::log('jama.models.in', ['brand' => $brandPath]);
        $html = $this->fetchHtml($this->base() . '/autodily/' . $brandPath . '/');
        if ($html === null) {
            return [];
        }

        $out = [];
        $prefix = '/autodily/' . $brandPath . '/';
        if (preg_match_all('/href="(' . preg_quote($prefix, '/') . '([^"\/]+)\/)"/i', $html, $m)) {
            foreach ($m[2] as $slug) {
                $slug = rawurldecode((string) $slug);
                if ($slug === '' || str_contains($slug, '.')) {
                    continue;
                }
                $out[$slug] = [
                    'slug' => $slug,
                    'name' => $this->humanizeSlug($slug),
                ];
            }
        }

        $list = array_values($out);
        usort($list, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        CatalogCallLogger::log('jama.models.out', ['brand' => $brandPath, 'count' => count($list)]);

        return $list;
    }

    /**
     * Všechny motorizace modelu (bez MAX_VEHICLES limitu).
     *
     * @return list<array{id: string, label: string, motor_slug: string, path: string}>
     */
    public function listAllMotors(string $brandPath, string $modelSlug): array
    {
        $brandPath = trim($brandPath, '/');
        $modelSlug = trim($modelSlug, '/');
        CatalogCallLogger::log('jama.motors.in', ['brand' => $brandPath, 'model' => $modelSlug]);
        $modelHref = '/autodily/' . $brandPath . '/' . $modelSlug . '/';
        $html = $this->fetchHtml($this->base() . $modelHref);
        if ($html === null) {
            return [];
        }

        $out = [];
        $modelPrefix = rtrim($modelHref, '/') . '/';
        if (!preg_match_all('/href="(' . preg_quote($modelPrefix, '/') . '([^"\/]+)\/)"/i', $html, $mm)) {
            return [];
        }
        foreach ($mm[2] as $motorSlug) {
            $motorSlug = rawurldecode((string) $motorSlug);
            if ($motorSlug === '' || str_contains($motorSlug, '.')) {
                continue;
            }
            $id = 'jama:' . $brandPath . ':' . $modelSlug . ':' . $motorSlug;
            $label = strtoupper($this->brandDisplayName($brandPath))
                . ' ' . $this->humanizeSlug($modelSlug)
                . ' ' . $this->humanizeSlug($motorSlug);
            $out[$motorSlug] = [
                'id' => $id,
                'label' => $label,
                'motor_slug' => $motorSlug,
                'path' => '/autodily/' . $brandPath . '/' . $modelSlug . '/' . $motorSlug . '/',
            ];
        }

        $list = array_values($out);
        CatalogCallLogger::log('jama.motors.out', [
            'brand' => $brandPath,
            'model' => $modelSlug,
            'count' => count($list),
        ]);

        return $list;
    }

    /**
     * Produkty kategorie bez limitu a bez importu obrázků (pro DB sync).
     *
     * @return list<array{id: string, name: string, name_original: ?string, oem: ?string, brand: ?string, image_url: ?string, attrs: array<string, mixed>}>
     */
    public function productsRaw(string $vehicleId, string $nodeId): array
    {
        $parsed = $this->parseVehicleId($vehicleId);
        $node = trim($nodeId, '/');
        if ($parsed === null || $node === '') {
            return [];
        }

        $path = rtrim($parsed['path'], '/') . '/' . $node . '/';
        $cacheKey = 'products_raw:' . $path;
        $remembered = $this->apiCache->remember(
            'jama',
            $cacheKey,
            function () use ($path): array {
                return ['items' => $this->fetchProductsForPathPaginated($path)];
            },
            6 * 3600,
            cacheEmpty: true,
        );

        $items = [];
        foreach ($remembered['data']['items'] ?? [] as $row) {
            if (!is_array($row) || !isset($row['id'], $row['name'])) {
                continue;
            }
            $items[] = $row;
        }

        return $items;
    }

    public function vehicles(?string $query = null): array
    {
        $q = trim((string) $query);
        CatalogCallLogger::log('jama.vehicles.in', ['query' => $q]);
        if ($q === '') {
            return [];
        }

        [$brandNeedle, $modelNeedle, $year] = $this->splitQuery($q);
        $brandPath = $this->brandPath($brandNeedle);
        if ($brandPath === null) {
            $this->softMessage = 'Jama: neznámá značka „' . $brandNeedle . '“.';
            CatalogCallLogger::log('jama.vehicles.out', ['count' => 0, 'reason' => 'unknown_brand']);

            return [];
        }

        $cacheKey = 'vehicles:' . mb_strtolower($brandPath . ':' . $modelNeedle . ':' . ($year ?? ''));
        $remembered = $this->apiCache->remember(
            'jama',
            $cacheKey,
            function () use ($brandPath, $modelNeedle, $year): array {
                return ['items' => $this->discoverVehicles($brandPath, $modelNeedle, $year)];
            },
            6 * 3600,
            cacheEmpty: false,
        );

        $out = [];
        foreach ($remembered['data']['items'] ?? [] as $row) {
            if (!is_array($row) || !isset($row['id'], $row['label'])) {
                continue;
            }
            $out[] = new CatalogVehicleOption(
                (string) $row['id'],
                (string) $row['label'],
                isset($row['hint']) && (string) $row['hint'] !== '' && !str_contains((string) $row['hint'], 'jama')
                    ? (string) $row['hint']
                    : null,
            );
        }

        CatalogCallLogger::log('jama.vehicles.out', ['count' => count($out), 'brandPath' => $brandPath]);

        return $out;
    }

    public function tree(string $vehicleId, ?string $parentNodeId = null): array
    {
        $parsed = $this->parseVehicleId($vehicleId);
        CatalogCallLogger::log('jama.tree.in', [
            'vehicleId' => $vehicleId,
            'parent' => $parentNodeId,
            'parsed' => $parsed,
        ]);
        if ($parsed === null) {
            $this->softMessage = 'Neplatné ID vozidla Jama (očekává se jama:…).';

            return [];
        }

        $motorPath = $parsed['path'];
        $parent = trim((string) $parentNodeId, '/');
        $pagePath = $parent === '' ? $motorPath : rtrim($motorPath, '/') . '/' . $parent . '/';
        $html = $this->fetchHtml($this->base() . $pagePath);
        if ($html === null) {
            return [];
        }

        $prefix = rtrim($pagePath, '/') . '/';
        $children = [];
        if (preg_match_all('/href="(' . preg_quote($prefix, '/') . '([^"\/?#]+)\\/?)"/i', $html, $m)) {
            foreach ($m[2] as $i => $seg) {
                $seg = rawurldecode((string) $seg);
                if ($seg === '' || str_contains($seg, '.')) {
                    continue;
                }
                $rel = $parent === '' ? $seg : $parent . '/' . $seg;
                $children[$rel] = $this->humanizeSlug($seg);
            }
        }

        // Má stránka produkty? (listový uzel)
        $hasProductsHere = str_contains($html, 'ajax-filters-placeholder')
            || str_contains($html, 'product-card-title-link')
            || str_contains($html, 'js-product-holder');

        $out = [];
        foreach ($children as $rel => $name) {
            $childPrefix = rtrim($motorPath, '/') . '/' . $rel . '/';
            $hasKids = preg_match(
                '/href="' . preg_quote($childPrefix, '/') . '[^"\/?#]+/i',
                $html,
            ) === 1;
            // Heuristika: když aktuální stránka nemá produkty a dítě existuje v menu, ber jako složku.
            $out[] = new CatalogNode(
                id: $rel,
                name: $name,
                nameOriginal: $rel,
                hasChildren: $hasKids || (!$hasProductsHere && $parent === ''),
                hasProducts: !$hasKids,
            );
        }

        // Když jsme v listu a děti jsou jen „hlubší“ SEO, pořád nabídni list jako produkty přes parent odkaz z UI.
        if ($out === [] && $hasProductsHere && $parent !== '') {
            // prázdný strom = UI půjde do products přes node=parent (už vybraný)
        }

        CatalogCallLogger::log('jama.tree.out', [
            'path' => $pagePath,
            'count' => count($out),
            'hasProductsHere' => $hasProductsHere,
        ]);

        return $out;
    }

    public function products(string $vehicleId, string $nodeId): array
    {
        $parsed = $this->parseVehicleId($vehicleId);
        $node = trim($nodeId, '/');
        CatalogCallLogger::log('jama.products.in', [
            'vehicleId' => $vehicleId,
            'nodeId' => $nodeId,
        ]);
        if ($parsed === null || $node === '') {
            return [];
        }

        $path = rtrim($parsed['path'], '/') . '/' . $node . '/';
        $cacheKey = 'products:' . $path;
        $remembered = $this->apiCache->remember(
            'jama',
            $cacheKey,
            function () use ($path): array {
                return ['items' => $this->fetchProductsForPath($path)];
            },
            3 * 3600,
            cacheEmpty: true,
        );

        $out = [];
        foreach (array_slice($remembered['data']['items'] ?? [], 0, self::MAX_PRODUCTS) as $row) {
            if (!is_array($row) || !isset($row['id'], $row['name'])) {
                continue;
            }
            $out[] = $this->toArticle($row);
        }

        CatalogCallLogger::log('jama.products.out', [
            'path' => $path,
            'count' => count($out),
            'fromCache' => (bool) ($remembered['fromCache'] ?? false),
        ]);

        return $out;
    }

    /**
     * @return list<CatalogArticle>
     */
    public function searchByArticle(string $article): array
    {
        $n = trim($article);
        if ($n === '' || mb_strlen($n) < 3) {
            return [];
        }

        CatalogCallLogger::log('jama.oem.in', ['n' => $n]);
        $html = $this->fetchHtml($this->base() . '/hledani/?search=' . rawurlencode($n));
        if ($html === null) {
            return [];
        }
        $items = $this->parseProductCards($html);
        CatalogCallLogger::log('jama.oem.out', ['n' => $n, 'count' => count($items)]);

        $out = [];
        foreach (array_slice($items, 0, self::MAX_PRODUCTS) as $row) {
            $out[] = $this->toArticle($row);
        }

        return $out;
    }

    public function suggestVehicleId(?string $brand, ?string $model): ?string
    {
        $opts = $this->vehicles(trim((string) $brand . ' ' . (string) $model));

        return $opts[0]->id ?? null;
    }

    public function productDetail(string $productPath): ?\Modules\PartsCatalog\Application\Dto\CatalogProductDetail
    {
        $path = $this->normalizeProductPath($productPath);
        if ($path === null) {
            $this->softMessage = 'Neplatný odkaz na díl.';

            return null;
        }

        CatalogCallLogger::log('jama.detail.in', ['path' => $path]);
        $cacheKey = 'detail:' . $path;
        $remembered = $this->apiCache->remember(
            'jama',
            $cacheKey,
            function () use ($path): array {
                $html = $this->fetchHtml($this->base() . $path);
                if ($html === null) {
                    return ['ok' => false];
                }

                return ['ok' => true, 'parsed' => $this->parseProductDetailHtml($html, $path)];
            },
            12 * 3600,
            cacheEmpty: false,
        );

        $data = $remembered['data'] ?? null;
        if (!is_array($data) || empty($data['ok']) || !is_array($data['parsed'] ?? null)) {
            CatalogCallLogger::log('jama.detail.out', ['ok' => false]);

            return null;
        }

        $parsed = $data['parsed'];
        $name = (string) ($parsed['name'] ?? 'Díl');
        $remoteImages = is_array($parsed['images'] ?? null) ? $parsed['images'] : [];
        $imageUrls = [];
        $mediaIds = [];
        foreach (array_slice($remoteImages, 0, 8) as $remote) {
            $remote = trim((string) $remote);
            if ($remote === '') {
                continue;
            }
            $asset = $this->images->import($remote, $name);
            if ($asset !== null) {
                $mediaIds[] = $asset->id();
                $local = $this->images->publicUrl($asset);
                if ($local !== null) {
                    $imageUrls[] = $local;
                    continue;
                }
            }
            $imageUrls[] = $remote;
        }

        $detail = new \Modules\PartsCatalog\Application\Dto\CatalogProductDetail(
            id: $path,
            name: $name,
            oem: isset($parsed['oem']) ? (string) $parsed['oem'] : null,
            brand: isset($parsed['brand']) ? (string) $parsed['brand'] : null,
            price: isset($parsed['price']) ? (string) $parsed['price'] : null,
            description: isset($parsed['description']) ? (string) $parsed['description'] : null,
            properties: is_array($parsed['properties'] ?? null) ? $parsed['properties'] : [],
            references: is_array($parsed['references'] ?? null) ? $parsed['references'] : [],
            imageUrls: $imageUrls,
            mediaIds: $mediaIds,
            sourceUrl: $this->base() . $path,
        );

        CatalogCallLogger::log('jama.detail.out', [
            'path' => $path,
            'images' => count($imageUrls),
            'props' => count($detail->properties),
            'refs' => count($detail->references),
        ]);

        return $detail;
    }

    /**
     * @return array{
     *   name: string,
     *   oem: ?string,
     *   brand: ?string,
     *   price: ?string,
     *   description: ?string,
     *   properties: list<array{label: string, value: string}>,
     *   references: list<array{brand: string, number: string}>,
     *   images: list<string>
     * }
     */
    private function parseProductDetailHtml(string $html, string $path): array
    {
        $name = '';
        if (preg_match('/<h1[^>]*>([\s\S]*?)<\/h1>/i', $html, $m) === 1) {
            $name = trim(html_entity_decode(strip_tags($m[1])));
        }
        if ($name === '') {
            $name = trim(str_replace(['-', '.html'], [' ', ''], basename($path)));
        }

        $oem = null;
        if (preg_match('/Katalogový kód\s*<strong>([^<]+)</u', $html, $m) === 1
            || preg_match('/katalogov[ýy]\s+k[oó]d[^<]*<[^>]+>([^<]+)/iu', $html, $m) === 1
        ) {
            $oem = trim(html_entity_decode($m[1]));
        }

        $brand = null;
        if ($oem !== null && $oem !== '' && preg_match('/\s([A-Z0-9][A-Za-z0-9 &\-\.]{1,40})\s+' . preg_quote($oem, '/') . '\s*$/u', $name, $bm)) {
            $brand = trim($bm[1]);
        }

        $price = null;
        if (preg_match('/priceValue[^>]*>\s*([^<]+)/', $html, $m) === 1) {
            $price = trim(html_entity_decode(str_replace("\xc2\xa0", ' ', strip_tags($m[1])))) . ' Kč';
        }

        $properties = [];
        if (preg_match('/data-tab="technical"[\s\S]*?<ul class="properties">([\s\S]*?)<\/ul>/i', $html, $block) === 1) {
            if (preg_match_all('/<li>\s*<span class="label">([^<]+)<\/span>\s*([^<]*)<\/li>/u', $block[1], $pm, PREG_SET_ORDER)) {
                foreach ($pm as $row) {
                    $label = trim(html_entity_decode($row[1]));
                    $value = trim(html_entity_decode($row[2]));
                    $label = rtrim($label, ':');
                    if ($label === '' || $value === '') {
                        continue;
                    }
                    $properties[] = ['label' => $label, 'value' => $value];
                }
            }
        }

        $references = [];
        if (preg_match('/data-tab="reference-numbers"[\s\S]*?<ul class="reference-numbers">([\s\S]*?)<\/ul>/i', $html, $block) === 1) {
            if (preg_match_all('/<span class="label">([^<]+)<\/span>\s*<a[^>]*>\s*([^<]+)\s*<\/a>/u', $block[1], $rm, PREG_SET_ORDER)) {
                foreach ($rm as $row) {
                    $references[] = [
                        'brand' => trim(html_entity_decode($row[1])),
                        'number' => trim(html_entity_decode($row[2])),
                    ];
                    if (count($references) >= 40) {
                        break;
                    }
                }
            }
        }

        $description = null;
        if (preg_match('/data-tab="description"[\s\S]*?<div[^>]*>([\s\S]*?)<\/div>/i', $html, $m) === 1) {
            $description = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]))) ?? '');
            if ($description === '') {
                $description = null;
            }
        }

        // Prefer large gallery shots (fotografie/max, else velke).
        $byKey = [];
        if (preg_match_all('/https?:\/\/image\.systemit\.cz\/fotografie\/(max|velke)\/([a-z0-9_\-]+\.(?:jpg|jpeg|png|webp))/i', $html, $im, PREG_SET_ORDER)) {
            foreach ($im as $row) {
                $size = strtolower($row[1]);
                $file = strtolower($row[2]);
                $key = preg_replace('/\.(jpg|jpeg|png|webp)$/', '', $file) ?? $file;
                $score = $size === 'max' ? 2 : 1;
                if (!isset($byKey[$key]) || $score > $byKey[$key]['score']) {
                    $byKey[$key] = ['score' => $score, 'url' => $row[0]];
                }
            }
        }
        $images = array_values(array_map(static fn (array $r): string => $r['url'], $byKey));
        if ($images === [] && preg_match_all('/https?:\/\/image\.systemit\.cz\/img\/product\/(?:medium|large)\/[^"\'\s]+/i', $html, $im2)) {
            $images = array_values(array_unique($im2[0]));
        }

        return [
            'name' => $name,
            'oem' => $oem,
            'brand' => $brand,
            'price' => $price,
            'description' => $description,
            'properties' => $properties,
            'references' => array_values($references),
            'images' => $images,
        ];
    }

    private function normalizeProductPath(string $productPath): ?string
    {
        $productPath = trim(html_entity_decode($productPath));
        if ($productPath === '') {
            return null;
        }
        if (str_starts_with($productPath, 'http://') || str_starts_with($productPath, 'https://')) {
            $path = parse_url($productPath, PHP_URL_PATH);
            $productPath = is_string($path) ? $path : '';
        }
        $productPath = '/' . ltrim($productPath, '/');
        if (!preg_match('/^\/[a-z0-9][a-z0-9\-_.\/]*\.html$/i', $productPath)) {
            return null;
        }
        if (str_contains($productPath, '..')) {
            return null;
        }

        return $productPath;
    }

    /**
     * @return list<array{id: string, label: string, hint: string}>
     */
    private function discoverVehicles(string $brandPath, string $modelNeedle, ?int $year): array
    {
        $brandHtml = $this->fetchHtml($this->base() . '/autodily/' . $brandPath . '/');
        if ($brandHtml === null) {
            return [];
        }

        $modelLinks = [];
        $prefix = '/autodily/' . $brandPath . '/';
        if (preg_match_all('/href="(' . preg_quote($prefix, '/') . '([^"\/]+)\/)"/i', $brandHtml, $m)) {
            foreach ($m[1] as $i => $href) {
                $slug = rawurldecode((string) $m[2][$i]);
                if ($modelNeedle !== '' && !$this->slugMatches($slug, $modelNeedle)) {
                    continue;
                }
                if ($year !== null && !$this->slugHasYearNear($slug, $year)) {
                    // rok v slugi (…-2012) — měkký filtr
                    if (!preg_match('/\b' . $year . '\b/', $slug) && !preg_match('/-(19|20)\d{2}\/?$/', $slug)) {
                        // nech projít, když model sedí — rok filtruje až motorizace
                    }
                }
                $modelLinks[$slug] = $href;
            }
        }

        if ($modelLinks === [] && $modelNeedle !== '') {
            // looser: obsahuje normalizovaný model
            if (preg_match_all('/href="(' . preg_quote($prefix, '/') . '([^"\/]+)\/)"/i', $brandHtml, $m)) {
                foreach ($m[1] as $i => $href) {
                    $slug = rawurldecode((string) $m[2][$i]);
                    if ($this->containsNormalized($slug, $modelNeedle)) {
                        $modelLinks[$slug] = $href;
                    }
                }
            }
        }

        if ($year !== null) {
            $filtered = [];
            foreach ($modelLinks as $slug => $href) {
                if (preg_match('/-(19|20)\d{2}/', $slug, $ym)) {
                    $y = (int) substr($ym[0], 1);
                    // model year start — dovol ±3
                    if (abs($y - $year) <= 4 || ($year >= $y && $year <= $y + 8)) {
                        $filtered[$slug] = $href;
                    }
                } else {
                    $filtered[$slug] = $href;
                }
            }
            if ($filtered !== []) {
                $modelLinks = $filtered;
            }
        }

        $out = [];
        foreach (array_slice($modelLinks, 0, 6, true) as $modelSlug => $modelHref) {
            $modelHtml = $this->fetchHtml($this->base() . $modelHref);
            if ($modelHtml === null) {
                continue;
            }
            $modelPrefix = rtrim($modelHref, '/') . '/';
            if (!preg_match_all('/href="(' . preg_quote($modelPrefix, '/') . '([^"\/]+)\/)"/i', $modelHtml, $mm)) {
                continue;
            }
            foreach ($mm[2] as $motorSlug) {
                $motorSlug = rawurldecode((string) $motorSlug);
                if ($motorSlug === '' || str_contains($motorSlug, '.')) {
                    continue;
                }
                $id = 'jama:' . $brandPath . ':' . $modelSlug . ':' . $motorSlug;
                $label = strtoupper(str_replace('-', ' ', $brandPath === 'skoda' ? 'skoda' : $brandPath))
                    . ' ' . $this->humanizeSlug($modelSlug)
                    . ' ' . $this->humanizeSlug($motorSlug);
                $out[] = [
                    'id' => $id,
                    'label' => $label,
                    'hint' => null,
                ];
                if (count($out) >= self::MAX_VEHICLES) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array{id: string, name: string, name_original: ?string, oem: ?string, brand: ?string, attrs: array<string, mixed>}>
     */
    private function fetchProductsForPath(string $path): array
    {
        return array_slice($this->fetchProductsForPathPaginated($path), 0, self::MAX_PRODUCTS);
    }

    /**
     * @return list<array{id: string, name: string, name_original: ?string, oem: ?string, brand: ?string, image_url: ?string, attrs: array<string, mixed>}>
     */
    private function fetchProductsForPathPaginated(string $path): array
    {
        $url = $this->base() . $path;
        $html = $this->fetchHtml($url);
        if ($html === null) {
            return [];
        }

        $mid = $this->dataAttr($html, 'tecdocmid');
        $pgid = $this->dataAttr($html, 'pgid');
        $seo = $this->dataAttr($html, 'tecdocseo');
        $modelSeo = $this->dataAttr($html, 'tecdocmodelseo');
        $lt = $this->dataAttr($html, 'listtype') ?: '1';
        $byId = [];

        if ($mid !== '' && $pgid !== '') {
            $ppp = 60;
            for ($os = 0; $os < 600; $os += $ppp) {
                $body = http_build_query([
                    'tecdocSeo' => $seo,
                    'tecdocModelSeo' => $modelSeo,
                    'tecdocMid' => $mid,
                    'tecdocVehicleType' => $this->dataAttr($html, 'tecdocvehicletype') ?: '1',
                    'ct' => '',
                    'pgid' => $pgid,
                    'srch' => '',
                    'lt' => $lt,
                    'manufacturers' => '',
                    'os' => (string) $os,
                    'ord' => '',
                    'ppp' => (string) $ppp,
                ]);
                try {
                    $res = $this->http->request(
                        'POST',
                        $this->base() . '/actions/ajax/getProducts.php?',
                        [
                            'Accept: application/json, text/javascript, */*; q=0.01',
                            'X-Requested-With: XMLHttpRequest',
                            'Origin: ' . $this->base(),
                            'Referer: ' . $url,
                        ],
                        $body,
                        'application/x-www-form-urlencoded',
                        45,
                        $this->cookiePath(),
                    );
                    if (str_contains($res['body'], 'Making sure you') || str_contains($res['body'], 'BotStopper')) {
                        CatalogCallLogger::log('jama.products.ajax_anubis', ['path' => $path, 'os' => $os]);
                        break;
                    }
                    $json = is_array($res['json']) ? $res['json'] : json_decode($res['body'], true);
                    if (!is_array($json) || !isset($json['productsHtml']) || !is_string($json['productsHtml'])) {
                        break;
                    }
                    $pageItems = $this->parseProductCards($json['productsHtml']);
                    if ($pageItems === []) {
                        break;
                    }
                    foreach ($pageItems as $row) {
                        $byId[(string) $row['id']] = $row;
                    }
                    if (count($pageItems) < $ppp) {
                        break;
                    }
                    usleep(150_000);
                } catch (\Throwable $e) {
                    CatalogCallLogger::log('jama.products.ajax_error', ['error' => $e->getMessage()]);
                    break;
                }
            }
        }

        if ($byId === []) {
            foreach ($this->parseProductCards($html) as $row) {
                $byId[(string) $row['id']] = $row;
            }
        }

        return array_values($byId);
    }

    private function brandDisplayName(string $slug): string
    {
        $slug = preg_replace('/^nahradni-dily-/', '', $slug) ?? $slug;

        return $this->humanizeSlug($slug);
    }

    /**
     * @param array{id: string, name: string, name_original: ?string, oem: ?string, brand: ?string, image_url?: ?string, attrs: array<string, mixed>} $row
     */
    private function toArticle(array $row): CatalogArticle
    {
        $imageUrl = isset($row['image_url']) ? trim((string) $row['image_url']) : '';
        if ($imageUrl === '' && isset($row['attrs']['image_url'])) {
            $imageUrl = trim((string) $row['attrs']['image_url']);
        }
        $mediaId = null;
        $localUrl = null;
        if ($imageUrl !== '') {
            $asset = $this->images->import($imageUrl, (string) $row['name']);
            if ($asset !== null) {
                $mediaId = $asset->id();
                $localUrl = $this->images->publicUrl($asset);
            }
        }

        $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
        if ($imageUrl !== '') {
            $attrs['image_url'] = $imageUrl;
        }
        if ($mediaId !== null) {
            $attrs['media_id'] = $mediaId;
        }

        return new CatalogArticle(
            id: (string) $row['id'],
            name: (string) $row['name'],
            nameOriginal: isset($row['name_original']) ? (string) $row['name_original'] : null,
            oem: isset($row['oem']) ? (string) $row['oem'] : null,
            brand: isset($row['brand']) ? (string) $row['brand'] : null,
            imageUrl: $localUrl ?? ($imageUrl !== '' ? $imageUrl : null),
            mediaId: $mediaId,
            attrs: $attrs,
        );
    }

    /**
     * @return list<array{id: string, name: string, name_original: ?string, oem: ?string, brand: ?string, image_url: ?string, attrs: array<string, mixed>}>
     */
    private function parseProductCards(string $html): array
    {
        $chunks = preg_split('/(?=<div[^>]*class="[^"]*product-card-insider)/u', $html) ?: [];
        $items = [];
        foreach ($chunks as $chunk) {
            if (!str_contains($chunk, 'product-card-title-link')) {
                continue;
            }
            $href = '';
            $title = '';
            if (preg_match('/product-card-title-link"[^>]*href="([^"]+)"[^>]*title="([^"]*)"/u', $chunk, $m) === 1
                || preg_match('/product-card-title-link"[^>]*href="([^"]+)"[^>]*>\s*([^<]+)/u', $chunk, $m) === 1
            ) {
                $href = html_entity_decode(trim($m[1]));
                $title = trim(html_entity_decode($m[2]));
            }
            if ($title === '') {
                continue;
            }
            $oem = null;
            if (preg_match('/Katalogový kód\s*<strong>([^<]+)</u', $chunk, $cm) === 1) {
                $oem = trim(html_entity_decode($cm[1]));
            }
            $brand = null;
            if ($oem !== null && $oem !== '' && preg_match('/\s([A-Z0-9][A-Za-z0-9 &\-\.]{1,40})\s+' . preg_quote($oem, '/') . '\s*$/u', $title, $bm)) {
                $brand = trim($bm[1]);
            }
            $imageUrl = null;
            if (preg_match('/src="(https?:\/\/image\.systemit\.cz[^"]+)"/i', $chunk, $im) === 1
                || preg_match('/product-card-image[^>]*src="([^"]+)"/i', $chunk, $im) === 1
                || preg_match('/src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/i', $chunk, $im) === 1
            ) {
                $imageUrl = html_entity_decode(trim($im[1]));
            }
            $id = $href !== '' ? $href : ('jama-p:' . md5($title . '|' . ($oem ?? '')));
            $items[] = [
                'id' => $id,
                'name' => $title,
                'name_original' => $title,
                'oem' => $oem !== '' ? $oem : null,
                'brand' => $brand,
                'image_url' => $imageUrl,
                'attrs' => [
                    'url' => $href,
                    'source' => 'jama',
                    'image_url' => $imageUrl,
                ],
            ];
        }

        return $items;
    }

    private function fetchHtml(string $url): ?string
    {
        $attempts = 0;
        while ($attempts < 5) {
            $attempts++;
            try {
                $res = $this->http->request(
                    'GET',
                    $url,
                    [
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language: cs-CZ,cs;q=0.9,en;q=0.8',
                        'Upgrade-Insecure-Requests: 1',
                        'Sec-Fetch-Dest: document',
                        'Sec-Fetch-Mode: navigate',
                        'Sec-Fetch-Site: none',
                        'Sec-Fetch-User: ?1',
                    ],
                    null,
                    'application/json',
                    45,
                    $this->cookiePath(),
                );
            } catch (\Throwable $e) {
                $this->softMessage = 'Jama: ' . $e->getMessage();

                return null;
            }
            if ($res['status'] === 429 || $res['status'] === 503) {
                $this->softMessage = 'Jama HTTP ' . $res['status'];
                $sleep = min(30, 2 ** $attempts);
                CatalogCallLogger::log('jama.http.retry', [
                    'url' => $url,
                    'status' => $res['status'],
                    'attempt' => $attempts,
                    'sleep' => $sleep,
                ]);
                sleep($sleep);
                continue;
            }
            if ($res['status'] < 200 || $res['status'] >= 300) {
                $this->softMessage = 'Jama HTTP ' . $res['status'];

                return null;
            }
            if (str_contains($res['body'], 'Making sure you') || str_contains($res['body'], 'BotStopper')) {
                $this->softMessage = 'Jama blokuje boty (Anubis). Zkus znovu nebo jiný provider.';

                return null;
            }

            return $res['body'];
        }

        return null;
    }

    private function dataAttr(string $html, string $name): string
    {
        if (preg_match('/data-' . preg_quote($name, '/') . '="([^"]*)"/i', $html, $m) === 1) {
            return html_entity_decode($m[1]);
        }

        return '';
    }

    /**
     * @return array{brandPath: string, modelSlug: string, motorSlug: string, path: string}|null
     */
    private function parseVehicleId(string $vehicleId): ?array
    {
        if (preg_match('/^jama:([^:]+):([^:]+):(.+)$/', $vehicleId, $m) !== 1) {
            return null;
        }
        $brandPath = $m[1];
        $modelSlug = $m[2];
        $motorSlug = $m[3];

        return [
            'brandPath' => $brandPath,
            'modelSlug' => $modelSlug,
            'motorSlug' => $motorSlug,
            'path' => '/autodily/' . $brandPath . '/' . $modelSlug . '/' . $motorSlug . '/',
        ];
    }

    private function brandPath(string $brandNeedle): ?string
    {
        $n = mb_strtolower(trim($brandNeedle));
        $n = strtr($n, ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z']);

        return self::BRAND_PATHS[$n] ?? null;
    }

    /**
     * @return array{0: string, 1: string, 2: ?int}
     */
    private function splitQuery(string $q): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? $q);
        $year = null;
        if (preg_match('/\b(19|20)\d{2}\b/', $q, $ym) === 1) {
            $year = (int) $ym[0];
            $q = trim(str_replace($ym[0], '', $q));
        }
        $parts = preg_split('/\s+/u', $q, 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? '', $year];
    }

    private function slugMatches(string $slug, string $needle): bool
    {
        return $this->containsNormalized($slug, $needle)
            || $this->containsNormalized($this->humanizeSlug($slug), $needle);
    }

    private function slugHasYearNear(string $slug, int $year): bool
    {
        return str_contains($slug, (string) $year);
    }

    private function humanizeSlug(string $slug): string
    {
        $s = str_replace(['-', '_'], ' ', $slug);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return mb_convert_case(trim($s), MB_CASE_TITLE, 'UTF-8');
    }

    private function containsNormalized(string $haystack, string $needle): bool
    {
        return str_contains($this->normalize($haystack), $this->normalize($needle));
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $map = ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z'];
        $s = strtr($s, $map);

        return preg_replace('/[^a-z0-9]+/', '', $s) ?? $s;
    }

    private function base(): string
    {
        $base = trim((string) $this->settings->get('partscatalog.jama.base_url', self::DEFAULT_BASE));

        return $base !== '' ? rtrim($base, '/') : self::DEFAULT_BASE;
    }

    private function cookiePath(): string
    {
        if ($this->cookieFile !== null) {
            return $this->cookieFile;
        }
        $dir = sys_get_temp_dir() . '/partscatalog-jama';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->cookieFile = $dir . '/cookies.txt';

        return $this->cookieFile;
    }
}

<?php

declare(strict_types=1);

namespace Modules\BikeService\Application\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\Settings;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductPrice;
use Modules\SearchEngines\Application\SearchEnginesProvider;

/**
 * Vyhledávání dílů v češtině + tržní ceny z více zdrojů (API / scrape).
 *
 * Trh se načítá progresivně: stránka nejdřív vrátí cache / prázdný stav,
 * jednotlivé zdroje se doplňují přes {@see searchMarketSource()}.
 */
final class PartsSearchService
{
    private const MARKET_TTL_SECONDS = 86400 * 30; // 1 měsíc
    private const MARKET_LIMIT = 40;

    public function __construct(
        private readonly BikeDataCatalogClient $catalog,
        private readonly EntityManagerInterface $em,
        private readonly CatalogCacheStore $cacheStore,
        private readonly CapabilityRegistry $capabilities,
        private readonly CurrencyBuilder $money,
        private readonly ?Settings $settings = null,
    ) {}

    /**
     * @return array{
     *   types: list<array{name_en: string, name_cs: string, alias: ?string, group_en: string, group_cs: string}>,
     *   products: list<array{id: string, sku: string, name: string, price: ?float, currency: string, url: string}>,
     *   market: list<array{
     *     id: string|int, name: string, url: string, min_price: ?float, max_price: ?float, currency: string,
     *     shop: ?string, source: string, image: ?string, description: ?string, brand: ?string,
     *     parameters: list<string>, rating: ?float, rating_count: int, availability: ?string
     *   }>,
     *   market_ok: bool,
     *   market_error: ?string,
     *   market_sources: list<string>,
     *   market_pending_sources: list<string>,
     *   market_async: bool,
     *   shop_links: list<array{id: string, name: string, region: string, url: string}>,
     *   market_cached: bool,
     *   market_cached_at: ?string,
     *   heureka_configured: bool,
     *   heureka_url: string,
     *   quick: list<string>
     * }
     */
    public function search(string $query, string $groupEn = '', string $taxonomyId = '', int $limit = 40, bool $fresh = false): array
    {
        $query = PartsSearchTokens::correctQuery(trim($query));
        $taxonomyId = trim($taxonomyId);
        if ($query === '' && $taxonomyId !== '') {
            $query = PartsSearchTokens::correctQuery(PartsTaxonomy::defaultQuery($taxonomyId));
        }
        $tokens = $query !== '' ? PartsSearchTokens::tokenize($query) : [];
        $taxonomyTokens = $taxonomyId !== '' ? PartsTaxonomy::marketTokens($taxonomyId) : [];
        $marketQuery = $query !== '' ? BikePartsMarketScope::marketplaceQuery($query) : '';
        $shopQuery = $query !== '' ? BikePartsMarketScope::bikeShopQuery($query) : '';
        $types = $this->searchTypes($query, $groupEn, $taxonomyId, $limit, $tokens);
        $products = $query !== '' ? $this->searchLocalProducts($query, 20, $tokens) : [];
        $marketBundle = $query !== ''
            ? $this->searchMarketProgressive($query, $marketQuery, $shopQuery, $tokens, $taxonomyId, $fresh, $taxonomyTokens)
            : [
                'items' => [],
                'ok' => false,
                'error' => null,
                'sources' => [],
                'pending_sources' => [],
                'async' => false,
                'heureka_configured' => false,
                'cached' => false,
                'cached_at' => null,
            ];

        return [
            'types' => $types,
            'products' => $products,
            'market' => $marketBundle['items'],
            'market_ok' => $marketBundle['ok'],
            'market_error' => $marketBundle['error'],
            'market_sources' => $marketBundle['sources'],
            'market_pending_sources' => $marketBundle['pending_sources'],
            'market_async' => (bool) ($marketBundle['async'] ?? false),
            'market_all_sources' => $this->marketSourceIds(),
            'market_cached' => (bool) ($marketBundle['cached'] ?? false),
            'market_cached_at' => $marketBundle['cached_at'] ?? null,
            'market_tokens' => $tokens,
            'shop_links' => $this->shopLinks($marketQuery !== '' ? $marketQuery : 'cyklistické díly'),
            'heureka_configured' => $marketBundle['heureka_configured'],
            'heureka_url' => $this->engineSearchUrl('heureka', $marketQuery !== '' ? $marketQuery : 'cyklistické díly'),
            'query' => $query,
            'quick' => [
                'Přehazovačka',
                'Přesmykač',
                'Kazeta',
                'Řetěz',
                'Brzdové destičky',
                'Kotouč',
                'Plášť',
                'Duše',
                'Vidlice',
                'Sedlo',
                'Baterie',
                'Motor',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function marketSourceIds(): array
    {
        $engines = $this->searchEngines();
        if ($engines === null) {
            return [];
        }
        $preferred = ['heureka', 'seznam', 'hledejceny', 'mojekolo'];
        $available = $engines->availableSourceIds();

        return array_values(array_filter(
            $preferred,
            static fn (string $id): bool => in_array($id, $available, true),
        ));
    }

    /**
     * Načte jeden tržní zdroj, uloží per-source cache a případně složí full bundle.
     *
     * @return array{
     *   ok: bool,
     *   source: string,
     *   items: list<array<string, mixed>>,
     *   error: ?string,
     *   sources: list<string>,
     *   pending_sources: list<string>,
     *   complete: bool,
     *   cached_at: ?string,
     *   has_more: bool,
     *   page: int
     * }
     */
    public function searchMarketSource(string $source, string $query, bool $fresh = false, string $taxonomyId = '', int $page = 1): array
    {
        $source = trim($source);
        $query = PartsSearchTokens::correctQuery(trim($query));
        $taxonomyId = trim($taxonomyId);
        $page = max(1, $page);
        if ($query === '' && $taxonomyId !== '') {
            $query = PartsSearchTokens::correctQuery(PartsTaxonomy::defaultQuery($taxonomyId));
        }
        $tokens = PartsSearchTokens::tokenize($query);
        $taxonomyTokens = $taxonomyId !== '' ? PartsTaxonomy::marketTokens($taxonomyId) : [];
        if ($query === '' || !in_array($source, $this->marketSourceIds(), true)) {
            return [
                'ok' => false,
                'source' => $source,
                'items' => [],
                'error' => 'bikeservice.error.parts_query_required',
                'sources' => [],
                'pending_sources' => [],
                'complete' => false,
                'cached_at' => null,
                'has_more' => false,
                'page' => $page,
            ];
        }

        $marketplaceQuery = BikePartsMarketScope::marketplaceQuery($query);
        $shopQuery = BikePartsMarketScope::bikeShopQuery($query);
        $hash = $this->marketCacheHash($query, $taxonomyId);
        $fullKey = $this->fullMarketCacheKey($hash);

        if ($fresh && $page === 1) {
            $this->invalidateMarketCache($hash);
        }

        $cached = $this->cacheStore->getJson($fullKey, self::MARKET_TTL_SECONDS);
        if ($page === 1 && !$fresh && is_array($cached) && isset($cached['items']) && is_array($cached['items']) && $cached['items'] !== []) {
            $allItems = $this->filterByTokens(
                $this->normalizeCachedItems($cached['items']),
                $tokens,
                $taxonomyTokens,
            );
            $fromSource = array_values(array_filter(
                $allItems,
                static fn (array $row): bool => (string) ($row['source'] ?? '') === $source,
            ));

            return [
                'ok' => true,
                'source' => $source,
                'items' => $fromSource,
                'error' => null,
                'sources' => isset($cached['sources']) && is_array($cached['sources'])
                    ? array_values(array_map('strval', $cached['sources']))
                    : [],
                'pending_sources' => [],
                'complete' => true,
                'cached_at' => isset($cached['cached_at']) && is_string($cached['cached_at']) ? $cached['cached_at'] : null,
                'has_more' => $source === 'heureka',
                'page' => $page,
            ];
        }

        if ($page > 1 && $source !== 'heureka') {
            return [
                'ok' => true,
                'source' => $source,
                'items' => [],
                'error' => null,
                'sources' => [],
                'pending_sources' => [],
                'complete' => true,
                'cached_at' => null,
                'has_more' => false,
                'page' => $page,
            ];
        }

        $fetched = $this->fetchOneMarketSource($source, $marketplaceQuery, $shopQuery, $tokens, $taxonomyTokens, $page);
        $fetchedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $srcKey = $this->sourceMarketCacheKey($hash, $source);
        try {
            if ($page === 1) {
                $this->cacheStore->putJson($srcKey, [
                    'ok' => $fetched['ok'],
                    'error' => $fetched['error'],
                    'items' => $fetched['items'],
                    'tokens' => $tokens,
                    'taxonomy' => $taxonomyId,
                    'fetched_at' => $fetchedAt,
                ]);
            } elseif ($fetched['items'] !== []) {
                $prev = $this->cacheStore->getJson($srcKey, self::MARKET_TTL_SECONDS);
                $prevItems = is_array($prev) && isset($prev['items']) && is_array($prev['items']) ? $prev['items'] : [];
                $this->cacheStore->putJson($srcKey, [
                    'ok' => true,
                    'error' => null,
                    'items' => $this->mergeMarketItemLists($prevItems, $fetched['items']),
                    'tokens' => $tokens,
                    'taxonomy' => $taxonomyId,
                    'fetched_at' => $fetchedAt,
                ]);
            }
        } catch (\Throwable) {
            // cache write must not break search
        }

        $merged = $this->mergeSourceCaches($hash, $marketplaceQuery, $shopQuery, $tokens, $taxonomyTokens);

        return [
            'ok' => $fetched['ok'],
            'source' => $source,
            'items' => $fetched['items'],
            'error' => $fetched['error'],
            'sources' => $merged['sources'],
            'pending_sources' => $merged['pending_sources'],
            'complete' => $merged['complete'],
            'cached_at' => $merged['cached_at'],
            'has_more' => (bool) ($fetched['has_more'] ?? false),
            'page' => $page,
        ];
    }

    /**
     * Cache / partial results — bez live scrapu všech zdrojů najednou.
     *
     * @param list<string> $tokens
     * @return array{
     *   items: list<array<string, mixed>>,
     *   ok: bool,
     *   error: ?string,
     *   sources: list<string>,
     *   pending_sources: list<string>,
     *   async: bool,
     *   heureka_configured: bool,
     *   cached: bool,
     *   cached_at: ?string
     * }
     */
    private function searchMarketProgressive(
        string $query,
        string $marketplaceQuery,
        string $shopQuery,
        array $tokens,
        string $taxonomyId = '',
        bool $fresh = false,
        array $taxonomyTokens = [],
    ): array {
        $heurekaOn = $this->heurekaConfigured();
        $hash = $this->marketCacheHash($query, $taxonomyId);
        $fullKey = $this->fullMarketCacheKey($hash);
        $expected = $this->marketSourceIds();

        // full + per-source klíče najednou (bez N+1 na catalog page)
        $prefetchKeys = [$fullKey];
        foreach ($expected as $source) {
            $prefetchKeys[] = $this->sourceMarketCacheKey($hash, $source);
        }
        $this->cacheStore->prefetch($prefetchKeys);

        if ($fresh) {
            $this->invalidateMarketCache($hash);

            return [
                'items' => [],
                'ok' => false,
                'error' => null,
                'sources' => [],
                'pending_sources' => $expected,
                'async' => true,
                'heureka_configured' => $heurekaOn,
                'cached' => false,
                'cached_at' => null,
            ];
        }

        $cached = $this->cacheStore->getJson($fullKey, self::MARKET_TTL_SECONDS);
        if (is_array($cached) && isset($cached['items']) && is_array($cached['items']) && $cached['items'] !== []) {
            return [
                'items' => $this->filterByTokens($this->normalizeCachedItems($cached['items']), $tokens, $taxonomyTokens),
                'ok' => true,
                'error' => null,
                'sources' => isset($cached['sources']) && is_array($cached['sources'])
                    ? array_values(array_map('strval', $cached['sources']))
                    : [],
                'pending_sources' => [],
                'async' => false,
                'heureka_configured' => $heurekaOn,
                'cached' => true,
                'cached_at' => isset($cached['cached_at']) && is_string($cached['cached_at']) ? $cached['cached_at'] : null,
            ];
        }

        $merged = $this->mergeSourceCaches($hash, $marketplaceQuery, $shopQuery, $tokens, $taxonomyTokens);
        if ($merged['complete']) {
            if ($merged['items'] !== []) {
                return [
                    'items' => $merged['items'],
                    'ok' => true,
                    'error' => null,
                    'sources' => $merged['sources'],
                    'pending_sources' => [],
                    'async' => false,
                    'heureka_configured' => $heurekaOn,
                    'cached' => true,
                    'cached_at' => $merged['cached_at'],
                ];
            }

            $stale = $this->cacheStore->getJsonStale($fullKey);
            if (is_array($stale) && isset($stale['items']) && is_array($stale['items']) && $stale['items'] !== []) {
                return [
                    'items' => $this->filterByTokens($this->normalizeCachedItems($stale['items']), $tokens, $taxonomyTokens),
                    'ok' => true,
                    'error' => null,
                    'sources' => isset($stale['sources']) && is_array($stale['sources'])
                        ? array_values(array_map('strval', $stale['sources']))
                        : [],
                    'pending_sources' => [],
                    'async' => false,
                    'heureka_configured' => $heurekaOn,
                    'cached' => true,
                    'cached_at' => isset($stale['cached_at']) && is_string($stale['cached_at']) ? $stale['cached_at'] : null,
                ];
            }

            return [
                'items' => [],
                'ok' => false,
                'error' => 'bikeservice.explore.no_results',
                'sources' => $merged['sources'],
                'pending_sources' => [],
                'async' => false,
                'heureka_configured' => $heurekaOn,
                'cached' => false,
                'cached_at' => $merged['cached_at'],
            ];
        }

        return [
            'items' => $merged['items'],
            'ok' => $merged['items'] !== [],
            'error' => null,
            'sources' => $merged['sources'],
            'pending_sources' => $merged['pending_sources'] !== [] ? $merged['pending_sources'] : $expected,
            'async' => true,
            'heureka_configured' => $heurekaOn,
            'cached' => $merged['items'] !== [],
            'cached_at' => $merged['cached_at'],
        ];
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $taxonomyTokens
     * @return array{ok: bool, error: ?string, items: list<array<string, mixed>>, has_more: bool}
     */
    private function fetchOneMarketSource(
        string $source,
        string $marketplaceQuery,
        string $shopQuery,
        array $tokens,
        array $taxonomyTokens = [],
        int $page = 1,
    ): array {
        try {
            $engines = $this->searchEngines();
            if ($engines === null) {
                return ['ok' => false, 'error' => 'bikeservice.error.api_request_failed', 'items' => [], 'has_more' => false];
            }
            $queryForSource = $source === 'mojekolo' ? $shopQuery : $marketplaceQuery;
            $limit = match ($source) {
                'heureka' => 40,
                'seznam' => 16,
                'hledejceny' => 10,
                'mojekolo' => 8,
                default => 12,
            };
            $res = $engines->search($source, $queryForSource, $limit, $page);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'bikeservice.error.api_request_failed', 'items' => [], 'has_more' => false];
        }

        if (!$res['ok']) {
            return [
                'ok' => false,
                'error' => isset($res['error']) && is_string($res['error']) ? $res['error'] : 'bikeservice.error.api_request_failed',
                'items' => [],
                'has_more' => false,
            ];
        }

        $items = [];
        foreach ($res['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = $this->mapMarketRow($row, $source, count($items));
            if (!BikePartsMarketScope::accepts($mapped, (string) $mapped['source'])) {
                continue;
            }
            if (!PartsSearchTokens::itemMatches($mapped, $tokens)) {
                continue;
            }
            if ($taxonomyTokens !== [] && !PartsSearchTokens::itemMatchesAny($mapped, $taxonomyTokens)) {
                continue;
            }
            unset($mapped['category_root'], $mapped['category_parent']);
            $items[] = $mapped;
        }

        return [
            'ok' => true,
            'error' => null,
            'items' => $items,
            'has_more' => (bool) ($res['has_more'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapMarketRow(array $row, string $source, int $index): array
    {
        $currency = (string) ($row['currency'] ?? $this->money->defaultCode());
        $minGross = isset($row['min_price']) && $row['min_price'] !== null ? (float) $row['min_price'] : null;
        $maxGross = isset($row['max_price']) && $row['max_price'] !== null ? (float) $row['max_price'] : null;
        $minPrice = $minGross !== null ? $this->money->withoutVat($minGross) : null;
        $maxPrice = $maxGross !== null ? $this->money->withoutVat($maxGross) : null;
        $money = $this->money->make($minPrice, $currency);

        return [
            'id' => $row['id'] ?? ($source . '-' . $index),
            'name' => (string) ($row['name'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'currency' => $currency,
            'money' => $money,
            'price_label' => $money->localized,
            'unit_price' => $minPrice,
            'shop' => isset($row['shop']) ? (string) $row['shop'] : $source,
            'source' => (string) ($row['source'] ?? $source),
            'image' => isset($row['image']) && is_string($row['image']) && $row['image'] !== '' ? $row['image'] : null,
            'description' => isset($row['description']) && is_string($row['description']) && $row['description'] !== ''
                ? mb_substr(trim($row['description']), 0, 420)
                : null,
            'brand' => isset($row['brand']) && is_string($row['brand']) && $row['brand'] !== '' ? $row['brand'] : null,
            'parameters' => isset($row['parameters']) && is_array($row['parameters'])
                ? array_values(array_filter(array_map(static fn ($p): string => trim((string) $p), $row['parameters'])))
                : [],
            'rating' => isset($row['rating']) && is_numeric($row['rating']) ? (float) $row['rating'] : null,
            'rating_count' => (int) ($row['rating_count'] ?? 0),
            'availability' => isset($row['availability']) && is_string($row['availability']) && $row['availability'] !== ''
                ? $row['availability']
                : null,
            'shop_count' => isset($row['shop_count']) && is_numeric($row['shop_count']) ? (int) $row['shop_count'] : null,
            'category_root' => isset($row['category_root']) ? (int) $row['category_root'] : null,
            'category_parent' => isset($row['category_parent']) ? (int) $row['category_parent'] : null,
        ];
    }

    /**
     * @param list<string> $tokens
     * @return array{
     *   items: list<array<string, mixed>>,
     *   sources: list<string>,
     *   pending_sources: list<string>,
     *   complete: bool,
     *   cached_at: ?string
     * }
     */
    private function mergeSourceCaches(
        string $hash,
        string $marketplaceQuery,
        string $shopQuery,
        array $tokens,
        array $taxonomyTokens = [],
    ): array {
        $expected = $this->marketSourceIds();
        $items = [];
        $sources = [];
        $pending = [];
        $latestAt = null;

        $sourceKeys = [];
        foreach ($expected as $source) {
            $sourceKeys[$source] = $this->sourceMarketCacheKey($hash, $source);
        }
        $cachedByKey = $this->cacheStore->getJsonMany(array_values($sourceKeys), self::MARKET_TTL_SECONDS);

        foreach ($expected as $source) {
            $raw = $cachedByKey[$sourceKeys[$source]] ?? null;
            if (!is_array($raw)) {
                $pending[] = $source;
                continue;
            }
            if (!empty($raw['ok']) && isset($raw['items']) && is_array($raw['items']) && $raw['items'] !== []) {
                $sources[] = $source;
                foreach ($raw['items'] as $row) {
                    if (is_array($row)) {
                        $items[] = $row;
                    }
                }
            }
            if (isset($raw['fetched_at']) && is_string($raw['fetched_at']) && $raw['fetched_at'] !== '') {
                if ($latestAt === null || $raw['fetched_at'] > $latestAt) {
                    $latestAt = $raw['fetched_at'];
                }
            }
        }

        $unique = $this->sortAndDedupeMarketItems($this->filterByTokens($items, $tokens, $taxonomyTokens));
        $complete = $pending === [];

        if ($complete && $unique !== []) {
            $cachedAt = $latestAt ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            try {
                $this->cacheStore->putJson($this->fullMarketCacheKey($hash), [
                    'items' => $unique,
                    'sources' => $sources,
                    'tokens' => $tokens,
                    'cached_at' => $cachedAt,
                    'marketplace_query' => $marketplaceQuery,
                    'shop_query' => $shopQuery,
                ]);
            } catch (\Throwable) {
                // ignore
            }

            return [
                'items' => $unique,
                'sources' => $sources,
                'pending_sources' => [],
                'complete' => true,
                'cached_at' => $cachedAt,
            ];
        }

        return [
            'items' => $unique,
            'sources' => $sources,
            'pending_sources' => $pending,
            'complete' => $complete,
            'cached_at' => $latestAt,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $tokens
     * @param list<string> $taxonomyTokens
     * @return list<array<string, mixed>>
     */
    private function filterByTokens(array $items, array $tokens, array $taxonomyTokens = []): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($tokens !== [] && !PartsSearchTokens::itemMatches($item, $tokens)) {
                continue;
            }
            if ($taxonomyTokens !== [] && !PartsSearchTokens::itemMatchesAny($item, $taxonomyTokens)) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    private function invalidateMarketCache(string $hash): void
    {
        try {
            $keys = [$this->fullMarketCacheKey($hash)];
            foreach ($this->marketSourceIds() as $source) {
                $keys[] = $this->sourceMarketCacheKey($hash, $source);
            }
            $this->cacheStore->deleteMany($keys);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * @param list<mixed> $existing
     * @param list<array<string, mixed>> $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeMarketItemLists(array $existing, array $incoming): array
    {
        $out = [];
        $seen = [];
        foreach (array_merge($existing, $incoming) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = mb_strtolower(trim((string) ($row['name'] ?? '')))
                . '|' . (string) ($row['source'] ?? '')
                . '|' . (string) ($row['id'] ?? $row['url'] ?? '');
            if ($key === '||' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function sortAndDedupeMarketItems(array $items): array
    {
        $prefer = $this->money->defaultCode();
        usort($items, static function (array $a, array $b) use ($prefer): int {
            $ca = ($a['currency'] ?? '') === $prefer ? 0 : 1;
            $cb = ($b['currency'] ?? '') === $prefer ? 0 : 1;
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }
            $pa = $a['min_price'] ?? null;
            $pb = $b['min_price'] ?? null;
            if ($pa === null && $pb === null) {
                return 0;
            }
            if ($pa === null) {
                return 1;
            }
            if ($pb === null) {
                return -1;
            }

            return $pa <=> $pb;
        });

        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $key = ComponentTranslations::normalize($name) . '|' . (string) ($item['source'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            unset($item['category_root'], $item['category_parent']);
            $unique[] = $item;
            if (count($unique) >= self::MARKET_LIMIT) {
                break;
            }
        }

        return $unique;
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string, mixed>>
     */
    private function normalizeCachedItems(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function heurekaConfigured(): bool
    {
        return $this->searchEngines()?->isAvailable('heureka') ?? false;
    }

    private function searchEngines(): ?SearchEnginesProvider
    {
        if (!$this->capabilities->has('searchengines')) {
            return null;
        }
        $cap = $this->capabilities->get('searchengines');

        return $cap instanceof SearchEnginesProvider ? $cap : null;
    }

    /**
     * @return list<array{id: string, name: string, region: string, url: string}>
     */
    private function shopLinks(string $query): array
    {
        return $this->searchEngines()?->searchLinks($query) ?? [];
    }

    private function engineSearchUrl(string $source, string $query): string
    {
        return $this->searchEngines()?->searchUrl($source, $query) ?? '';
    }

    private function marketCacheHash(string $query, string $taxonomyId = ''): string
    {
        $engines = $this->searchEngines();
        $srcFingerprint = $engines !== null ? implode(',', $engines->availableSourceIds()) : 'none';

        return hash(
            'sha256',
            PartsSearchTokens::cacheFingerprint($query)
            . '|tax:' . $taxonomyId
            . '|src:' . $srcFingerprint,
        );
    }

    private function fullMarketCacheKey(string $hash): string
    {
        return 'parts_market:v1:' . $hash;
    }

    private function sourceMarketCacheKey(string $hash, string $source): string
    {
        return 'parts_market_src:v1:' . $hash . ':' . $source;
    }

    /**
     * @param list<string> $tokens
     * @return list<array{name_en: string, name_cs: string, alias: ?string, group_en: string, group_cs: string}>
     */
    private function searchTypes(string $query, string $groupEn, string $taxonomyId, int $limit, array $tokens = []): array
    {
        $enNames = $query !== '' ? ComponentTranslations::resolveQueryToEnglishNames($query) : [];
        $raw = $this->catalog->componentTypes('', $groupEn);
        if (!$raw['ok']) {
            return [];
        }

        $taxonomyGroupEn = match ($taxonomyId) {
            'drivetrain' => 'Drivetrain',
            'brakes' => 'Brakes',
            'wheels' => 'Wheels',
            'frame_fork' => 'Frame and Fork',
            'accessories', 'cockpit', 'pedals', 'ebike' => '',
            default => '',
        };

        $q = ComponentTranslations::normalize($query);
        $out = [];
        foreach ($raw['items'] as $item) {
            $nameEn = (string) ($item['name'] ?? '');
            if ($nameEn === '' || $nameEn === 'unknown') {
                continue;
            }
            $gEn = (string) ($item['group'] ?? '');
            if ($taxonomyGroupEn !== '' && $gEn !== $taxonomyGroupEn) {
                if (in_array($taxonomyId, ['drivetrain', 'brakes', 'wheels', 'frame_fork'], true)) {
                    continue;
                }
            }
            if ($taxonomyId === 'ebike' && !str_starts_with($nameEn, 'E-vehicle')) {
                continue;
            }
            if ($taxonomyId === 'cockpit' && !in_array($nameEn, ['Handlebar', 'Grips/Tape', 'Stem', 'Saddle', 'Seatpost', 'Water Bottle Cage'], true)) {
                continue;
            }
            if ($taxonomyId === 'pedals' && $nameEn !== 'Pedals' && $nameEn !== 'Toe Clips') {
                continue;
            }
            if ($taxonomyId === 'accessories' && !in_array($gEn, ['Additional Parts', 'Cargo'], true) && !in_array($nameEn, ['Fender', 'Rack', 'Lights', 'Kickstand', 'Bell/Noisemaker'], true)) {
                continue;
            }

            $nameCs = ComponentTranslations::component($nameEn);
            $alias = isset($item['secondary_name']) && is_string($item['secondary_name']) ? $item['secondary_name'] : null;
            if ($tokens !== []) {
                $hay = ComponentTranslations::normalize($nameCs . ' ' . $nameEn . ' ' . (string) $alias);
                $synHit = in_array($nameEn, $enNames, true);
                $anyToken = false;
                foreach ($tokens as $t) {
                    if (str_contains($hay, $t)) {
                        $anyToken = true;
                        break;
                    }
                }
                if (!$synHit && !$anyToken) {
                    continue;
                }
            } elseif ($q !== '') {
                $hay = ComponentTranslations::normalize($nameCs . ' ' . $nameEn . ' ' . (string) $alias);
                $synHit = in_array($nameEn, $enNames, true);
                if (!$synHit && !str_contains($hay, $q)) {
                    continue;
                }
            }

            $out[] = [
                'name_en' => $nameEn,
                'name_cs' => $nameCs,
                'alias' => $alias,
                'group_en' => $gEn,
                'group_cs' => ComponentTranslations::group($gEn),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $tokens
     * @return list<array{id: string, sku: string, name: string, price: ?float, currency: string, url: string}>
     */
    private function searchLocalProducts(string $query, int $limit, array $tokens = []): array
    {
        if (!class_exists(Product::class)) {
            return [];
        }
        $qb = $this->em->getRepository(Product::class)->createQueryBuilder('p')
            ->setMaxResults(max($limit * 4, 40));
        if (function_exists('self_repair_search')) {
            self_repair_search($qb, 'p', ['name', 'sku'], $query);
        } else {
            $qb->andWhere('LOWER(p.name) LIKE :q OR LOWER(p.sku) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($query) . '%');
        }
        /** @var list<Product> $products */
        $products = $qb->getQuery()->getResult();
        if ($products === []) {
            return [];
        }
        $ids = array_map(static fn (Product $p): string => $p->id(), $products);
        $prices = $this->currentPrices($ids);

        $out = [];
        foreach ($products as $p) {
            if ($tokens !== []) {
                $hay = ComponentTranslations::normalize($p->name() . ' ' . $p->sku());
                if (!PartsSearchTokens::hayMatches($hay, $tokens)) {
                    continue;
                }
            }
            $amount = $prices[$p->id()] ?? null;
            $out[] = [
                'id' => $p->id(),
                'sku' => $p->sku(),
                'name' => $p->name(),
                'price' => $amount !== null ? (float) $amount : null,
                'currency' => $this->money->defaultCode(),
                'url' => '/admin/products/' . $p->id(),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $productIds
     * @return array<string, string>
     */
    private function currentPrices(array $productIds): array
    {
        if ($productIds === [] || !class_exists(ProductPrice::class)) {
            return [];
        }
        /** @var list<ProductPrice> $rows */
        $rows = $this->em->getRepository(ProductPrice::class)->createQueryBuilder('pp')
            ->where('pp.productId IN (:ids)')
            ->andWhere('pp.currencyCode = :c')
            ->setParameter('ids', $productIds)
            ->setParameter('c', $this->money->defaultCode())
            ->orderBy('pp.validFrom', 'DESC')
            ->getQuery()
            ->getResult();
        $now = new \DateTimeImmutable('today');
        $map = [];
        foreach ($rows as $price) {
            if (!$price instanceof ProductPrice || isset($map[$price->productId()])) {
                continue;
            }
            if ($price->validFrom() !== null && $price->validFrom() > $now) {
                continue;
            }
            if ($price->validTo() !== null && $price->validTo() < $now) {
                continue;
            }
            $map[$price->productId()] = number_format((float) $price->amount(), 2, '.', '');
        }

        return $map;
    }
}

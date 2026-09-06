<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\PartsCatalog\Domain\CatalogApiCache;

/**
 * Persistuje odpovědi katalogového API do DB — další request nevolá remote.
 */
final class CatalogApiCacheStore
{
    /** @var array<string, CatalogApiCache|false> false = known miss in this request */
    private array $rowByCacheKey = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Přednačte řádky cache jedním IN dotazem (pro N preloadů bez N SELECT).
     *
     * @param list<string> $logicalKeys
     */
    public function prefetch(string $provider, array $logicalKeys): void
    {
        $wanted = [];
        foreach ($logicalKeys as $key) {
            $cacheKey = $this->cacheKey($provider, $key);
            if (!array_key_exists($cacheKey, $this->rowByCacheKey)) {
                $wanted[$cacheKey] = true;
            }
        }
        if ($wanted === []) {
            return;
        }

        /** @var list<CatalogApiCache> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('c')
            ->from(CatalogApiCache::class, 'c')
            ->where('c.cacheKey IN (:keys)')
            ->setParameter('keys', array_keys($wanted))
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            if (!$row instanceof CatalogApiCache) {
                continue;
            }
            $this->rowByCacheKey[$row->cacheKey()] = $row;
            unset($wanted[$row->cacheKey()]);
        }
        foreach (array_keys($wanted) as $cacheKey) {
            $this->rowByCacheKey[$cacheKey] = false;
        }
    }

    /**
     * Vrátí dekódovaný payload nebo null (miss / expired / invalid) — bez remote fetche.
     */
    public function getDecoded(string $provider, string $key, ?int $ttlSeconds = null): mixed
    {
        $row = $this->findRow($provider, $key);
        if (!$row instanceof CatalogApiCache) {
            return null;
        }

        $age = time() - $row->fetchedAt()->getTimestamp();
        if ($ttlSeconds !== null && $age >= $ttlSeconds) {
            return null;
        }

        try {
            return json_decode($row->payloadJson(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param callable(): mixed $fetcher
     * @return array{data: mixed, fromCache: bool}
     */
    public function remember(
        string $provider,
        string $key,
        callable $fetcher,
        ?int $ttlSeconds = null,
        bool $cacheEmpty = true,
    ): array {
        $row = $this->findRow($provider, $key);

        if ($row instanceof CatalogApiCache) {
            $age = time() - $row->fetchedAt()->getTimestamp();
            if ($ttlSeconds === null || $age < $ttlSeconds) {
                try {
                    $data = json_decode($row->payloadJson(), true, 512, JSON_THROW_ON_ERROR);
                    if ($cacheEmpty || !$this->isEmptyPayload($data)) {
                        CatalogCallLogger::log('cache.hit', [
                            'provider' => $provider,
                            'key' => mb_substr($key, 0, 160),
                            'ageSec' => $age,
                        ]);

                        return ['data' => $data, 'fromCache' => true];
                    }
                } catch (\JsonException) {
                    // fall through
                }
            }
        }

        $data = $fetcher();
        if (!$cacheEmpty && $this->isEmptyPayload($data)) {
            CatalogCallLogger::log('cache.skip_empty', [
                'provider' => $provider,
                'key' => mb_substr($key, 0, 160),
            ]);

            return ['data' => $data, 'fromCache' => false];
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $cacheKey = $this->cacheKey($provider, $key);
        if ($row instanceof CatalogApiCache) {
            $row->refresh($json);
        } else {
            $row = new CatalogApiCache($provider, $cacheKey, $key, $json);
            $this->em->persist($row);
        }
        $this->em->flush();
        $this->rowByCacheKey[$cacheKey] = $row;
        CatalogCallLogger::log('cache.store', [
            'provider' => $provider,
            'key' => mb_substr($key, 0, 160),
            'bytes' => strlen($json),
        ]);

        return ['data' => $data, 'fromCache' => false];
    }

    private function findRow(string $provider, string $key): ?CatalogApiCache
    {
        $cacheKey = $this->cacheKey($provider, $key);
        if (array_key_exists($cacheKey, $this->rowByCacheKey)) {
            $cached = $this->rowByCacheKey[$cacheKey];

            return $cached instanceof CatalogApiCache ? $cached : null;
        }

        $row = $this->em->getRepository(CatalogApiCache::class)->findOneBy(['cacheKey' => $cacheKey]);
        if ($row instanceof CatalogApiCache) {
            $this->rowByCacheKey[$cacheKey] = $row;

            return $row;
        }

        $this->rowByCacheKey[$cacheKey] = false;

        return null;
    }

    private function cacheKey(string $provider, string $key): string
    {
        return hash('sha256', $provider . '|' . $key);
    }

    private function isEmptyPayload(mixed $data): bool
    {
        if (!is_array($data)) {
            return $data === null || $data === '' || $data === [];
        }
        foreach (['items', 'nodes', 'products', 'vehicles'] as $k) {
            if (array_key_exists($k, $data) && is_array($data[$k])) {
                return $data[$k] === [];
            }
        }

        return $data === [];
    }

    /**
     * Fulltext přes uložené productList payloady (OEM / značka / název).
     *
     * @return list<array{id?: string, name: string, oem: ?string, brand: ?string, image_url?: ?string, provider: string}>
     */
    public function searchProducts(string $query, int $limit = 40): array
    {
        return $this->searchProductHits($query, $limit, null);
    }

    /**
     * Fulltext jen v dílech daného katalogového vozidla (cache products:/path/…).
     *
     * @return list<array{id?: string, name: string, oem: ?string, brand: ?string, image_url?: ?string, provider: string}>
     */
    public function searchProductsForVehicle(string $query, string $vehiclePathPrefix, int $limit = 60): array
    {
        $prefix = trim($vehiclePathPrefix);
        if ($prefix === '') {
            return [];
        }

        return $this->searchProductHits($query, $limit, $prefix);
    }

    /**
     * @return list<array{id?: string, name: string, oem: ?string, brand: ?string, image_url?: ?string, provider: string}>
     */
    private function searchProductHits(string $query, int $limit, ?string $vehiclePathPrefix): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $tokens = $this->searchTokens($q);
        if ($tokens === []) {
            return [];
        }
        $out = [];
        $seen = [];
        $pathNeedle = $vehiclePathPrefix !== null
            ? mb_strtolower(rtrim($vehiclePathPrefix, '/') . '/')
            : null;

        foreach ($this->iterateProductRows($pathNeedle !== null ? 400 : 120, $pathNeedle) as $hit) {
            $hay = mb_strtolower(implode(' ', array_filter([
                $hit['name'],
                $hit['oem'] ?? '',
                $hit['brand'] ?? '',
            ])));
            if ($hay === '' || !$this->tokensMatch($hay, $tokens)) {
                continue;
            }
            $dedupe = mb_strtolower(($hit['oem'] ?? '') . '|' . $hit['name'] . '|' . ($hit['id'] ?? ''));
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $out[] = $hit;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function searchTokens(string $query): array
    {
        $parts = preg_split('/[\s,;+]+/u', mb_strtolower(trim($query))) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) >= 2) {
                $tokens[] = $part;
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @param list<string> $tokens */
    private function tokensMatch(string $haystack, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!str_contains($haystack, $token)) {
                return false;
            }
        }

        return $tokens !== [];
    }

    /**
     * Poslední uložené díly z cache (bez filtru značky) — výchozí browse.
     *
     * @return list<array{id?: string, name: string, oem: ?string, brand: ?string, image_url?: ?string, provider: string}>
     */
    public function listRecentProducts(int $limit = 50): array
    {
        $out = [];
        $seen = [];
        foreach ($this->iterateProductRows(80) as $hit) {
            $key = mb_strtolower(($hit['oem'] ?? '') . '|' . $hit['name'] . '|' . ($hit['brand'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $hit;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return \Generator<int, array{id?: string, name: string, oem: ?string, brand: ?string, image_url?: ?string, provider: string}>
     */
    private function iterateProductRows(int $maxCacheRows, ?string $pathNeedle = null): \Generator
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(CatalogApiCache::class, 'c')
            ->where('c.logicalKey LIKE :prod OR c.logicalKey LIKE :raw OR c.logicalKey LIKE :items')
            ->setParameter('prod', 'products:%')
            ->setParameter('raw', 'products_raw:%')
            ->setParameter('items', '%product%')
            ->orderBy('c.fetchedAt', 'DESC')
            ->setMaxResults($maxCacheRows);

        if ($pathNeedle !== null && $pathNeedle !== '') {
            $qb->andWhere('LOWER(c.logicalKey) LIKE :path')
                ->setParameter('path', '%' . $pathNeedle . '%');
        }

        /** @var list<CatalogApiCache> $rows */
        $rows = $qb->getQuery()->getResult();

        foreach ($rows as $row) {
            if (!$row instanceof CatalogApiCache) {
                continue;
            }
            $key = $row->logicalKey();
            if (
                !str_starts_with($key, 'products:')
                && !str_starts_with($key, 'products_raw:')
                && !str_contains($key, 'productList')
                && !str_contains($key, 'articles')
            ) {
                continue;
            }
            if ($pathNeedle !== null && $pathNeedle !== '' && !str_contains(mb_strtolower($key), $pathNeedle)) {
                continue;
            }
            try {
                $payload = json_decode($row->payloadJson(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($payload)) {
                continue;
            }
            $items = [];
            if (isset($payload['items']) && is_array($payload['items'])) {
                $items = $payload['items'];
            } elseif (array_is_list($payload)) {
                $items = $payload;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = $this->pick($item, ['name', 'n', 'title', 'label', 'nm', 'articleName', 'genericArticleDescription']);
                $oem = $this->pick($item, ['oe', 'oem', 'nr', 'ns', 'articleNumber', 'articleNo']);
                $brand = $this->pick($item, ['brand', 'make', 'mfd', 'mfr', 'manufacturer', 'brandName']);
                $id = $this->pick($item, ['id', 'url', 'href']);
                $image = $this->pick($item, ['image_url', 'image', 'img']);
                if ($image === '' && isset($item['attrs']) && is_array($item['attrs'])) {
                    $image = $this->pick($item['attrs'], ['image_url', 'image']);
                }
                if ($name === '' && $oem === '') {
                    continue;
                }
                yield [
                    'id' => $id !== '' ? $id : null,
                    'name' => $name !== '' ? $name : ($oem !== '' ? $oem : '—'),
                    'oem' => $oem !== '' ? $oem : null,
                    'brand' => $brand !== '' ? $brand : null,
                    'image_url' => $image !== '' ? $image : null,
                    'provider' => $row->provider(),
                ];
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private function pick(array $row, array $keys): string
    {
        foreach ($keys as $k) {
            if (!empty($row[$k]) && is_scalar($row[$k])) {
                $v = trim((string) $row[$k]);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }
}

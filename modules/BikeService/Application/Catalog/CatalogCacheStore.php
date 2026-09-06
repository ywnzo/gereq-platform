<?php

declare(strict_types=1);

namespace Modules\BikeService\Application\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Modules\BikeService\Domain\CatalogCache;

/**
 * DB cache pro bike_data CSV + tržní odpovědi (JSON včetně URL obrázků).
 */
final class CatalogCacheStore
{
    /** @var array<string, CatalogCache|null> */
    private array $memo = [];

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function get(string $key, int $ttlSeconds): ?string
    {
        $row = $this->find($key);
        if ($row === null) {
            return null;
        }
        $age = time() - $row->fetchedAt()->getTimestamp();
        if ($age >= $ttlSeconds) {
            return null;
        }

        return $row->payload();
    }

    /** Stale fallback (i po expiraci TTL). */
    public function getStale(string $key): ?string
    {
        $row = $this->find($key);

        return $row?->payload();
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    public function getJson(string $key, int $ttlSeconds): ?array
    {
        $raw = $this->get($key, $ttlSeconds);
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<string> $keys
     * @return array<string, array<string, mixed>|list<mixed>|null>
     */
    public function getJsonMany(array $keys, int $ttlSeconds): array
    {
        $keys = $this->normalizeKeys($keys);
        $this->prefetch($keys);
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->getJson($key, $ttlSeconds);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    public function getJsonStale(string $key): ?array
    {
        $raw = $this->getStale($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     */
    public function putJson(string $key, array $payload): void
    {
        $this->put($key, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function put(string $key, string $payload): void
    {
        $row = $this->find($key);
        if ($row instanceof CatalogCache) {
            $row->refresh($payload);
        } else {
            $row = new CatalogCache($key, $payload);
            $this->em->persist($row);
            $this->memo[$this->normalizeKey($key)] = $row;
        }
        $this->em->flush();
    }

    public function delete(string $key): void
    {
        $this->deleteMany([$key]);
    }

    /**
     * @param list<string> $keys
     */
    public function deleteMany(array $keys): void
    {
        $keys = $this->normalizeKeys($keys);
        if ($keys === []) {
            return;
        }
        $this->prefetch($keys);
        $removed = false;
        foreach ($keys as $key) {
            $row = $this->memo[$key] ?? null;
            if (!$row instanceof CatalogCache) {
                unset($this->memo[$key]);
                continue;
            }
            $this->em->remove($row);
            unset($this->memo[$key]);
            $removed = true;
        }
        if ($removed) {
            $this->em->flush();
        }
    }

    /**
     * Načte více klíčů jedním SELECT … IN (…) a naplní memo (prevence N+1).
     *
     * @param list<string> $keys
     */
    public function prefetch(array $keys): void
    {
        $missing = [];
        foreach ($this->normalizeKeys($keys) as $key) {
            if (!array_key_exists($key, $this->memo)) {
                $missing[] = $key;
            }
        }
        if ($missing === []) {
            return;
        }

        /** @var list<CatalogCache> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('c')
            ->from(CatalogCache::class, 'c')
            ->where('c.cacheKey IN (:keys)')
            ->setParameter('keys', $missing)
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            if ($row instanceof CatalogCache) {
                $this->memo[$row->cacheKey()] = $row;
            }
        }
        foreach ($missing as $key) {
            if (!array_key_exists($key, $this->memo)) {
                $this->memo[$key] = null;
            }
        }
    }

    private function find(string $key): ?CatalogCache
    {
        $key = $this->normalizeKey($key);
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $this->prefetch([$key]);

        return $this->memo[$key] ?? null;
    }

    private function normalizeKey(string $key): string
    {
        return mb_substr(trim($key), 0, 120);
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function normalizeKeys(array $keys): array
    {
        $out = [];
        $seen = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $key = $this->normalizeKey($key);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $key;
        }

        return $out;
    }
}

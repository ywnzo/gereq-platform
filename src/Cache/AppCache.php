<?php

declare(strict_types=1);

namespace Gereq\Cache;

use Gereq\Config\Repository;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Thin app cache API (remember / put / forget) over PSR-6.
 */
final class AppCache
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly Repository $config,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->pool->getItem($this->normalize($key));
        if (!$item->isHit()) {
            return $default;
        }

        return $item->get();
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        $item = $this->pool->getItem($this->normalize($key));
        $item->set($value);
        $seconds = $ttl ?? (int) $this->config->get('cache.default_ttl', 3600);
        if ($seconds > 0) {
            $item->expiresAfter($seconds);
        }
        $this->pool->save($item);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $item = $this->pool->getItem($this->normalize($key));
        if ($item->isHit()) {
            return $item->get();
        }

        $value = $callback();
        $item->set($value);
        $seconds = $ttl ?? (int) $this->config->get('cache.default_ttl', 3600);
        if ($seconds > 0) {
            $item->expiresAfter($seconds);
        }
        $this->pool->save($item);

        return $value;
    }

    public function forget(string $key): bool
    {
        return $this->pool->deleteItem($this->normalize($key));
    }

    public function flush(): bool
    {
        return $this->pool->clear();
    }

    public function pool(): CacheItemPoolInterface
    {
        return $this->pool;
    }

    private function normalize(string $key): string
    {
        $key = preg_replace('/[^A-Za-z0-9_.\-]+/', '_', $key) ?? $key;

        return substr($key, 0, 200);
    }
}

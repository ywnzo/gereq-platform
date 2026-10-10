<?php

declare(strict_types=1);

namespace Gereq\Cache;

use Gereq\Config\Repository;
use Gereq\Publish\ContentReadContext;
use Gereq\Setting\Settings;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Persistent cache for anonymous public storefront API payloads.
 * Storage (file|redis) and TTL come from Core settings.
 */
final class StoreFrontCache
{
    public const SETTING_ENABLED = 'storefront.cache_enabled';
    public const SETTING_DRIVER = 'storefront.cache_driver';
    public const SETTING_TTL = 'storefront.cache_ttl';

    public const DRIVER_FILE = 'file';
    public const DRIVER_REDIS = 'redis';

    private ?CacheItemPoolInterface $pool = null;

    private ?string $poolDriver = null;

    public function __construct(
        private readonly CacheFactory $factory,
        private readonly Settings $settings,
        private readonly Repository $config,
        private readonly ContentReadContext $readContext,
    ) {
    }

    public function enabled(): bool
    {
        $default = (bool) $this->config->get('cache.storefront.enabled', true);

        return (bool) $this->settings->getLive(self::SETTING_ENABLED, $default);
    }

    public function driver(): string
    {
        $default = (string) $this->config->get('cache.storefront.driver', self::DRIVER_FILE);
        $raw = strtolower(trim((string) $this->settings->getLive(self::SETTING_DRIVER, $default)));

        return $raw === self::DRIVER_REDIS ? self::DRIVER_REDIS : self::DRIVER_FILE;
    }

    public function ttl(): int
    {
        $default = (int) $this->config->get('cache.storefront.ttl', 300);
        $ttl = (int) $this->settings->getLive(self::SETTING_TTL, $default);

        return max(0, $ttl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->shouldCache()) {
            return $default;
        }

        $item = $this->pool()->getItem($this->normalize($key));
        if (!$item->isHit()) {
            return $default;
        }

        return $item->get();
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        if (!$this->shouldCache()) {
            return;
        }

        $item = $this->pool()->getItem($this->normalize($key));
        $item->set($value);
        $seconds = $ttl ?? $this->ttl();
        if ($seconds > 0) {
            $item->expiresAfter($seconds);
        }
        $this->pool()->save($item);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        if (!$this->shouldCache()) {
            return $callback();
        }

        $normalized = $this->normalize($key);
        $item = $this->pool()->getItem($normalized);
        if ($item->isHit()) {
            return $item->get();
        }

        $value = $callback();
        $item->set($value);
        $seconds = $ttl ?? $this->ttl();
        if ($seconds > 0) {
            $item->expiresAfter($seconds);
        }
        $this->pool()->save($item);

        return $value;
    }

    /**
     * Like remember(), but does not persist null (keeps 404s cold until content appears).
     *
     * @template T
     * @param callable(): (T|null) $callback
     * @return T|null
     */
    public function rememberHit(string $key, callable $callback, ?int $ttl = null): mixed
    {
        if (!$this->shouldCache()) {
            return $callback();
        }

        $normalized = $this->normalize($key);
        $item = $this->pool()->getItem($normalized);
        if ($item->isHit()) {
            return $item->get();
        }

        $value = $callback();
        if ($value === null) {
            return null;
        }

        $item->set($value);
        $seconds = $ttl ?? $this->ttl();
        if ($seconds > 0) {
            $item->expiresAfter($seconds);
        }
        $this->pool()->save($item);

        return $value;
    }

    public function forget(string $key): bool
    {
        if ($this->pool === null && !$this->enabled()) {
            return false;
        }

        return $this->pool()->deleteItem($this->normalize($key));
    }

    public function flush(): bool
    {
        $ok = $this->pool()->clear();
        $this->pool = null;
        $this->poolDriver = null;

        return $ok;
    }

    public function pool(): CacheItemPoolInterface
    {
        $driver = $this->driver();
        if ($this->pool === null || $this->poolDriver !== $driver) {
            $this->pool = $this->factory->createStorefront($driver);
            $this->poolDriver = $driver;
        }

        return $this->pool;
    }

    private function shouldCache(): bool
    {
        return $this->enabled() && $this->readContext->isProduction();
    }

    private function normalize(string $key): string
    {
        $key = 'sf.' . $key;
        $key = preg_replace('/[^A-Za-z0-9_.\-]+/', '_', $key) ?? $key;

        return substr($key, 0, 200);
    }
}

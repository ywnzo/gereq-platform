<?php

declare(strict_types=1);

namespace Gereq\Cache;

use Doctrine\DBAL\DriverManager;
use Gereq\Config\Repository;
use Gereq\Database\DbalConnectionParams;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class CacheFactory
{
    public function __construct(
        private readonly Repository $config,
        private readonly string $basePath,
    ) {
    }

    public function create(): CacheItemPoolInterface
    {
        $driver = (string) $this->config->get('cache.driver', 'file');
        $namespace = (string) $this->config->get('cache.prefix', 'gereq');

        return match ($driver) {
            'array' => new ArrayAdapter(),
            'redis' => $this->redisOrFile($namespace),
            'database', 'db' => $this->database($namespace),
            default => new FilesystemAdapter($namespace, 0, $this->basePath . '/storage/cache/data'),
        };
    }

    /**
     * Dedicated storefront pool (file or redis). Falls back to file when Redis is unavailable.
     */
    public function createStorefront(string $driver): CacheItemPoolInterface
    {
        $namespace = (string) $this->config->get('cache.storefront.prefix', 'storefront');
        $normalized = strtolower(trim($driver)) === 'redis' ? 'redis' : 'file';

        if ($normalized === 'redis') {
            try {
                return $this->redis($namespace);
            } catch (\Throwable) {
                // Public site must keep serving even without Redis.
            }
        }

        $dir = $this->basePath . '/storage/cache/storefront';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return new FilesystemAdapter($namespace, 0, $dir);
    }

    private function redisOrFile(string $namespace): CacheItemPoolInterface
    {
        try {
            return $this->redis($namespace);
        } catch (\Throwable) {
            return new FilesystemAdapter($namespace, 0, $this->basePath . '/storage/cache/data');
        }
    }

    private function redis(string $namespace): CacheItemPoolInterface
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException(
                'CACHE_DRIVER=redis requires the PHP redis extension (ext-redis). '
                . 'Use CACHE_DRIVER=file when Redis is not available.'
            );
        }

        $host = (string) $this->config->get('cache.redis.host', '127.0.0.1');
        $port = (int) $this->config->get('cache.redis.port', 6379);
        $password = $this->config->get('cache.redis.password');

        $auth = is_string($password) && $password !== '' ? $password . '@' : '';
        $dsn = sprintf('redis://%s%s:%d?timeout=2', $auth, $host, $port);

        $connection = RedisAdapter::createConnection($dsn);

        return new RedisAdapter($connection, $namespace);
    }

    private function database(string $namespace): CacheItemPoolInterface
    {
        $connection = DriverManager::getConnection($this->dbParams());
        $table = (string) $this->config->get('cache.database.table', 'cache_items');
        $adapter = new DoctrineDbalAdapter($connection, $namespace, 0, [
            'db_table' => $table,
        ]);

        try {
            $adapter->createTable();
        } catch (\Throwable) {
            // Table already exists or DB not ready yet.
        }

        return $adapter;
    }

    /** @return array<string, mixed> */
    private function dbParams(): array
    {
        return DbalConnectionParams::forConfig($this->config, $this->basePath);
    }
}

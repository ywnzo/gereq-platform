<?php

declare(strict_types=1);

namespace Gereq\Cache;

use Doctrine\DBAL\DriverManager;
use Gereq\Config\Repository;
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
            'redis' => $this->redis($namespace),
            'database', 'db' => $this->database($namespace),
            default => new FilesystemAdapter($namespace, 0, $this->basePath . '/storage/cache/data'),
        };
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
        $dsn = sprintf('redis://%s%s:%d', $auth, $host, $port);

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
        $driver = (string) $this->config->get('database.connection', 'sqlite');

        return match ($driver) {
            'mysql' => [
                'driver' => 'pdo_mysql',
                'host' => $this->config->get('database.host'),
                'port' => $this->config->get('database.port'),
                'dbname' => $this->config->get('database.name'),
                'user' => $this->config->get('database.username'),
                'password' => $this->config->get('database.password'),
                'charset' => 'utf8mb4',
            ],
            'pgsql' => [
                'driver' => 'pdo_pgsql',
                'host' => $this->config->get('database.host'),
                'port' => $this->config->get('database.port') ?: 5432,
                'dbname' => $this->config->get('database.name'),
                'user' => $this->config->get('database.username'),
                'password' => $this->config->get('database.password'),
                'charset' => 'utf8',
            ],
            default => [
                'driver' => 'pdo_sqlite',
                'path' => $this->sqlitePath(),
            ],
        };
    }

    private function sqlitePath(): string
    {
        $path = (string) $this->config->get('database.database', 'storage/database.sqlite');
        if (!str_starts_with($path, '/')) {
            $path = $this->basePath . '/' . $path;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $path;
    }
}

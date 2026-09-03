<?php

declare(strict_types=1);

namespace Gereq\Cache;

use Gereq\Config\Repository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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

        return match ($driver) {
            'array' => new ArrayAdapter(),
            'redis' => $this->redis(),
            default => new FilesystemAdapter('gereq', 0, $this->basePath . '/storage/cache'),
        };
    }

    private function redis(): CacheItemPoolInterface
    {
        $host = (string) $this->config->get('cache.redis.host', '127.0.0.1');
        $port = (int) $this->config->get('cache.redis.port', 6379);
        $password = $this->config->get('cache.redis.password');

        $auth = is_string($password) && $password !== '' ? $password . '@' : '';
        $dsn = sprintf('redis://%s%s:%d', $auth, $host, $port);

        $connection = RedisAdapter::createConnection($dsn);

        return new RedisAdapter($connection, 'gereq');
    }
}

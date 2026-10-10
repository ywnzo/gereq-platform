<?php

declare(strict_types=1);

namespace Gereq\Http\Session;

use Gereq\Config\Repository;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

final class SessionHandlerFactory
{
    public function __construct(
        private readonly Repository $config,
        private readonly string $basePath,
    ) {
    }

    public function create(): \SessionHandlerInterface
    {
        $driver = (string) $this->config->get('session.driver', 'file');

        return match ($driver) {
            'redis' => $this->redis(),
            default => $this->files(),
        };
    }

    private function files(): NativeFileSessionHandler
    {
        $relative = (string) $this->config->get('session.files', 'storage/sessions');
        $path = str_starts_with($relative, '/') ? $relative : $this->basePath . '/' . ltrim($relative, '/');

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Nelze vytvořit session adresář: ' . $path);
        }

        return new NativeFileSessionHandler($path);
    }

    private function redis(): \SessionHandlerInterface
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('SESSION_DRIVER=redis vyžaduje PHP rozšíření redis.');
        }

        $host = (string) $this->config->get('session.redis.host', '127.0.0.1');
        $port = (int) $this->config->get('session.redis.port', 6379);
        $password = $this->config->get('session.redis.password');
        $ttl = max(60, (int) $this->config->get('session.lifetime', 120) * 60);
        $prefix = (string) $this->config->get('session.redis.prefix', 'gereq:session:');

        $redis = new \Redis();
        if (!$redis->connect($host, $port, 2.0)) {
            throw new \RuntimeException(sprintf('Nelze se připojit k Redis pro session (%s:%d).', $host, $port));
        }

        if (is_string($password) && $password !== '') {
            $redis->auth($password);
        }

        $handler = new RedisSessionHandler($redis, [
            'prefix' => $prefix,
            'ttl' => $ttl,
        ]);

        // Symfony RedisSessionHandler has no locking — wrap so concurrent AJAX
        // cannot clobber CSRF / auth session keys (HTTP 419 on publish).
        return new LockingSessionHandler($handler, $redis, $prefix . 'lock:');
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Queue;

use Gereq\Config\Repository;
use Redis;

final class Queue
{
    public function __construct(
        private readonly Repository $config,
        private readonly string $basePath,
    ) {
    }

    public function dispatch(Job $job): void
    {
        $driver = (string) $this->config->get('queue.driver', 'sync');

        if ($driver === 'sync') {
            $job->handle();

            return;
        }

        $payload = serialize($job);

        if ($driver === 'redis') {
            $this->pushRedis($payload);

            return;
        }

        $this->pushFile($payload);
    }

    public function pop(): ?Job
    {
        $driver = (string) $this->config->get('queue.driver', 'sync');
        $payload = $driver === 'redis' ? $this->popRedis() : $this->popFile();

        if ($payload === null) {
            return null;
        }

        $job = unserialize($payload);

        return $job instanceof Job ? $job : null;
    }

    private function pushFile(string $payload): void
    {
        $dir = $this->basePath . '/storage/tmp/queue';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($dir . '/' . uniqid('job_', true) . '.job', $payload);
    }

    private function popFile(): ?string
    {
        $dir = $this->basePath . '/storage/tmp/queue';
        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/*.job') ?: [];
        if ($files === []) {
            return null;
        }

        sort($files);
        $file = $files[0];
        $payload = file_get_contents($file);
        unlink($file);

        return $payload === false ? null : $payload;
    }

    private function pushRedis(string $payload): void
    {
        $redis = $this->redis();
        $redis->rPush('gereq:queue', $payload);
    }

    private function popRedis(): ?string
    {
        $redis = $this->redis();
        $payload = $redis->lPop('gereq:queue');

        return is_string($payload) ? $payload : null;
    }

    private function redis(): Redis
    {
        if (!class_exists(Redis::class)) {
            throw new \RuntimeException('PHP rozšíření redis není nainstalované.');
        }

        $redis = new \Redis();
        $redis->connect(
            (string) $this->config->get('cache.redis.host', '127.0.0.1'),
            (int) $this->config->get('cache.redis.port', 6379),
        );

        return $redis;
    }
}

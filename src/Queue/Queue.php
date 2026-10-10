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

        $job = $this->unserializeJob($payload);
        if ($job instanceof Job) {
            return $job;
        }

        // Payload už je z fronty pryč — zaloguj, ať joby „mizí“ tiše.
        $preview = substr($payload, 0, 180);
        error_log('GEREQ queue: nelze unserialize job payload: ' . $preview);
        $this->storeFailedPayload($payload, 'unserialize_failed');

        return null;
    }

    private function unserializeJob(string $payload): ?Job
    {
        if (!preg_match('/^O:\d+:"([^"]+)"/', $payload, $m)) {
            return null;
        }
        $class = $m[1];
        if (!class_exists($class) || !is_a($class, Job::class, true)) {
            return null;
        }

        $job = @unserialize($payload, ['allowed_classes' => [$class]]);

        return $job instanceof Job ? $job : null;
    }

    private function storeFailedPayload(string $payload, string $reason): void
    {
        $dir = $this->basePath . '/storage/tmp/queue-failed';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . date('Ymd-His') . '_' . bin2hex(random_bytes(4)) . '.job';
        @file_put_contents($file, $reason . "\n" . $payload);
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

        $host = (string) $this->config->get('cache.redis.host', '127.0.0.1');
        $port = (int) $this->config->get('cache.redis.port', 6379);
        $password = $this->config->get('cache.redis.password');

        $redis = new \Redis();
        if (!$redis->connect($host, $port, 2.0)) {
            throw new \RuntimeException(sprintf('Nelze se připojit k Redis pro frontu (%s:%d).', $host, $port));
        }

        if (is_string($password) && $password !== '') {
            $redis->auth($password);
        }

        return $redis;
    }
}

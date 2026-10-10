<?php

declare(strict_types=1);

namespace Gereq\Queue;

use DateTimeInterface;
use Gereq\Config\Repository;
use Redis;
use RuntimeException;
use Throwable;

final class Queue
{
    private const REDIS_READY = 'gereq:queue';

    private const REDIS_DELAYED = 'gereq:queue:delayed';

    public function __construct(
        private readonly Repository $config,
        private readonly string $basePath,
        private readonly ?FailedJobStore $failed = null,
        private readonly ?JobRunStore $runs = null,
    ) {
    }

    public function dispatch(Job $job): void
    {
        if ($this->driver() === 'sync') {
            $this->runTracked($job, JobRun::TRIGGER_SYNC);

            return;
        }

        $this->push($job);
    }

    /**
     * Plan a job for later. Under redis/file the payload sits in delayed storage until
     * available_at; the worker promotes it on pop. Sync has no worker — runs inline now.
     */
    public function later(Job $job, int|DateTimeInterface $delay): void
    {
        $availableAt = $this->resolveAvailableAt($delay);

        if ($this->driver() === 'sync') {
            $this->runTracked($job, JobRun::TRIGGER_SYNC);

            return;
        }

        if ($availableAt <= time()) {
            $this->push($job);

            return;
        }

        $this->pushDelayed($this->encodeJob($job), $availableAt);
    }

    /**
     * Put a job on the ready queue without the sync short-circuit of dispatch().
     * Under the sync driver this still runs inline (used by failed-job retry).
     */
    public function push(Job $job): void
    {
        if ($this->driver() === 'sync') {
            $this->runTracked($job, JobRun::TRIGGER_SYNC);

            return;
        }

        $this->pushRaw($this->encodeJob($job));
    }

    /**
     * Execute a job and write a queue_runs row. Used by the sync driver and queue:work.
     */
    public function runTracked(Job $job, string $trigger, ?string $rawPayload = null): void
    {
        $startedAt = new \DateTimeImmutable();
        $t0 = hrtime(true);
        $payload = $rawPayload ?? $this->encodeJob($job);
        try {
            $job->handle();
            $this->recordRun(
                $job,
                JobRun::STATUS_SUCCESS,
                $trigger,
                $startedAt,
                (int) round((hrtime(true) - $t0) / 1_000_000),
                null,
                $payload,
            );
        } catch (Throwable $e) {
            $this->recordRun(
                $job,
                JobRun::STATUS_FAILED,
                $trigger,
                $startedAt,
                (int) round((hrtime(true) - $t0) / 1_000_000),
                $e,
                $payload,
            );
            throw $e;
        }
    }

    public function runs(): ?JobRunStore
    {
        return $this->runs;
    }

    private function recordRun(
        Job $job,
        string $status,
        string $trigger,
        \DateTimeImmutable $startedAt,
        int $durationMs,
        ?Throwable $error,
        ?string $rawPayload,
    ): void {
        if ($this->runs === null) {
            return;
        }

        try {
            $this->runs->recordJob($job, $status, $trigger, $startedAt, $durationMs, $error, $rawPayload);
        } catch (Throwable $e) {
            error_log('GEREQ queue: queue_runs write failed: ' . $e->getMessage());
        }
    }

    public function pushRaw(string $payload): void
    {
        $driver = $this->driver();
        if ($driver === 'sync') {
            throw new RuntimeException('Cannot push a raw payload while QUEUE_DRIVER=sync.');
        }

        if ($driver === 'redis') {
            $this->pushRedis($payload);

            return;
        }

        $this->pushFile($payload);
    }

    public function pop(): ?Job
    {
        $payload = $this->popRaw();
        if ($payload === null) {
            return null;
        }

        $job = $this->unserializePayload($payload);
        if ($job instanceof Job) {
            return $job;
        }

        $preview = substr($payload, 0, 180);
        error_log('GEREQ queue: cannot unserialize job payload: ' . $preview);
        $this->failRaw($payload, 'unserialize_failed');

        return null;
    }

    public function popRaw(): ?string
    {
        if ($this->driver() === 'sync') {
            return null;
        }

        $this->promoteDue();

        return $this->driver() === 'redis' ? $this->popRedis() : $this->popFile();
    }

    public function unserializePayload(string $payload): ?Job
    {
        $trimmed = ltrim($payload);
        if (str_starts_with($trimmed, '{')) {
            $job = $this->decodeJsonPayload($trimmed);
            if ($job instanceof Job) {
                return $job;
            }
        }

        // Legacy PHP serialize dual-read (drain in-flight jobs after deploy).
        if (!preg_match('/^O:\d+:"([^"]+)"/', $payload, $m)) {
            return null;
        }
        $class = $m[1];
        if (!$this->isAllowedJobClass($class)) {
            return null;
        }

        $job = @unserialize($payload, ['allowed_classes' => [$class]]);

        return $job instanceof Job ? $job : null;
    }

    public function encodeJob(Job $job): string
    {
        try {
            return json_encode([
                'v' => 1,
                'type' => $job::class,
                'data' => $this->jobData($job),
            ], JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Cannot JSON-encode queue job: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private function jobData(Job $job): array
    {
        $ref = new \ReflectionClass($job);
        $data = [];
        foreach ($ref->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $data[$prop->getName()] = $prop->getValue($job);
        }

        return $data;
    }

    private function decodeJsonPayload(string $payload): ?Job
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $class = $decoded['type'] ?? null;
        $data = $decoded['data'] ?? null;
        if (!is_string($class) || !is_array($data) || !$this->isAllowedJobClass($class)) {
            return null;
        }

        try {
            $ref = new \ReflectionClass($class);
            $ctor = $ref->getConstructor();
            if ($ctor === null) {
                $instance = $ref->newInstance();

                return $instance instanceof Job ? $instance : null;
            }
            $args = [];
            foreach ($ctor->getParameters() as $param) {
                $name = $param->getName();
                if (array_key_exists($name, $data)) {
                    $args[] = $data[$name];
                    continue;
                }
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                    continue;
                }

                return null;
            }
            $instance = $ref->newInstanceArgs($args);

            return $instance instanceof Job ? $instance : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isAllowedJobClass(string $class): bool
    {
        return class_exists($class) && is_a($class, Job::class, true);
    }

    /**
     * Jobs waiting in the ready queue. Null when the storage backend cannot be reached.
     */
    public function pending(): ?int
    {
        $driver = $this->driver();

        if ($driver === 'sync') {
            return 0;
        }

        try {
            if ($driver === 'redis') {
                return (int) $this->redis()->lLen(self::REDIS_READY);
            }

            $dir = $this->fileDir();
            if (!is_dir($dir)) {
                return 0;
            }

            return count(glob($dir . '/*.job') ?: []);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Jobs waiting for their available_at. Null when the backend cannot be reached.
     */
    public function delayed(): ?int
    {
        $driver = $this->driver();

        if ($driver === 'sync') {
            return 0;
        }

        try {
            if ($driver === 'redis') {
                return (int) $this->redis()->zCard(self::REDIS_DELAYED);
            }

            $dir = $this->delayedDir();
            if (!is_dir($dir)) {
                return 0;
            }

            return count(glob($dir . '/*.job') ?: []);
        } catch (Throwable) {
            return null;
        }
    }

    public function maxAttempts(): int
    {
        return max(1, (int) $this->config->get('queue.max_attempts', 3));
    }

    public function failedStore(): ?FailedJobStore
    {
        return $this->failed;
    }

    /**
     * Record a failure and re-queue while attempts remain. Returns the audit row
     * when storage is available, otherwise null (failure is still logged).
     */
    public function fail(Job $job, string $payload, Throwable $error): ?FailedJob
    {
        return $this->failRaw($payload, (string) $error, $job::class);
    }

    public function failRaw(string $payload, string $exception, string $job = ''): ?FailedJob
    {
        if ($this->failed === null) {
            $this->storeFailedPayload($payload, $exception);

            return null;
        }

        try {
            $row = $this->failed->recordFailure($payload, $exception, $job);
            if ($row->attempts() < $this->maxAttempts()) {
                $this->pushRaw($payload);
            }

            return $row;
        } catch (Throwable $e) {
            error_log('GEREQ queue: failed_jobs write failed: ' . $e->getMessage());
            $this->storeFailedPayload($payload, $exception);

            return null;
        }
    }

    /** Clear a failed_jobs row after a previously failing job succeeds on retry. */
    public function clearFailure(string $payload): void
    {
        if ($this->failed === null) {
            return;
        }

        try {
            $this->failed->forgetByPayload($payload);
        } catch (Throwable $e) {
            error_log('GEREQ queue: failed_jobs clear failed: ' . $e->getMessage());
        }
    }

    private function driver(): string
    {
        return (string) $this->config->get('queue.driver', 'sync');
    }

    private function resolveAvailableAt(int|DateTimeInterface $delay): int
    {
        if ($delay instanceof DateTimeInterface) {
            return $delay->getTimestamp();
        }

        if ($delay <= 0) {
            return time();
        }

        // Values up to a day are treated as relative seconds; larger as unix timestamps.
        if ($delay < 86400 * 10) {
            return time() + $delay;
        }

        return $delay;
    }

    private function pushDelayed(string $payload, int $availableAt): void
    {
        if ($this->driver() === 'redis') {
            $this->redis()->zAdd(
                self::REDIS_DELAYED,
                $availableAt,
                QueuedPayload::encodeDelayedMember($payload),
            );

            return;
        }

        $dir = $this->delayedDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = sprintf('%s/%d_%s.job', $dir, $availableAt, uniqid('job_', true));
        file_put_contents($file, $payload);
    }

    private function promoteDue(): void
    {
        if ($this->driver() === 'redis') {
            $this->promoteDueRedis();

            return;
        }

        $this->promoteDueFile();
    }

    private function promoteDueRedis(): void
    {
        $redis = $this->redis();
        $now = (string) time();
        $members = $redis->zRangeByScore(self::REDIS_DELAYED, '-inf', $now);
        if (!is_array($members) || $members === []) {
            return;
        }

        foreach ($members as $member) {
            if (!is_string($member)) {
                continue;
            }
            $body = QueuedPayload::decodeDelayedMember($member);
            if ($body === null) {
                $redis->zRem(self::REDIS_DELAYED, $member);
                continue;
            }
            $redis->rPush(self::REDIS_READY, $body);
            $redis->zRem(self::REDIS_DELAYED, $member);
        }
    }

    private function promoteDueFile(): void
    {
        $dir = $this->delayedDir();
        if (!is_dir($dir)) {
            return;
        }

        $now = time();
        foreach (glob($dir . '/*.job') ?: [] as $file) {
            $base = basename($file);
            if (preg_match('/^(\d+)_/', $base, $m) !== 1) {
                continue;
            }
            if ((int) $m[1] > $now) {
                continue;
            }
            $payload = file_get_contents($file);
            if ($payload === false) {
                continue;
            }
            $this->pushFile($payload);
            @unlink($file);
        }
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

    private function fileDir(): string
    {
        return $this->basePath . '/storage/tmp/queue';
    }

    private function delayedDir(): string
    {
        return $this->basePath . '/storage/tmp/queue-delayed';
    }

    private function pushFile(string $payload): void
    {
        $dir = $this->fileDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($dir . '/' . uniqid('job_', true) . '.job', $payload);
    }

    private function popFile(): ?string
    {
        $dir = $this->fileDir();
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
        $this->redis()->rPush(self::REDIS_READY, $payload);
    }

    private function popRedis(): ?string
    {
        $payload = $this->redis()->lPop(self::REDIS_READY);

        return is_string($payload) ? $payload : null;
    }

    private function redis(): Redis
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('PHP redis extension is not installed.');
        }

        $host = (string) $this->config->get('cache.redis.host', '127.0.0.1');
        $port = (int) $this->config->get('cache.redis.port', 6379);
        $password = $this->config->get('cache.redis.password');

        $redis = new Redis();
        if (!$redis->connect($host, $port, 2.0)) {
            throw new RuntimeException(sprintf('Cannot connect to Redis for the queue (%s:%d).', $host, $port));
        }

        if (is_string($password) && $password !== '') {
            $redis->auth($password);
        }

        return $redis;
    }
}

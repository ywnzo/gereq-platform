<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Queue;

use Gereq\Config\Repository;
use Gereq\Queue\Queue;
use Gereq\Testing\TestCase;

final class QueueTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . '/gereq-queue-test-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/storage/tmp/queue', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->basePath);
        parent::tearDown();
    }

    public function testFileDriverRoundTripAndPendingCount(): void
    {
        $queue = $this->queue(['driver' => 'file']);
        $job = new QueueTestJob('alpha');

        self::assertSame(0, $queue->pending());
        $queue->dispatch($job);
        self::assertSame(1, $queue->pending());

        $payload = $queue->popRaw();
        self::assertIsString($payload);
        self::assertSame(0, $queue->pending());
        self::assertStringStartsWith('{', ltrim($payload));

        $restored = $queue->unserializePayload($payload);
        self::assertInstanceOf(QueueTestJob::class, $restored);
        self::assertSame('alpha', $restored->value);
    }

    public function testLegacySerializePayloadStillDecodes(): void
    {
        $queue = $this->queue(['driver' => 'file']);
        $legacy = serialize(new QueueTestJob('legacy'));

        $restored = $queue->unserializePayload($legacy);
        self::assertInstanceOf(QueueTestJob::class, $restored);
        self::assertSame('legacy', $restored->value);
    }

    public function testSyncDispatchRunsInline(): void
    {
        $queue = $this->queue(['driver' => 'sync']);
        $job = new QueueTestJob('sync');

        $queue->dispatch($job);
        self::assertTrue($job->handled);
        self::assertSame(0, $queue->pending());
    }

    public function testMaxAttemptsDefaultsToThree(): void
    {
        $queue = $this->queue([]);
        self::assertSame(3, $queue->maxAttempts());

        $queue = $this->queue(['max_attempts' => 5]);
        self::assertSame(5, $queue->maxAttempts());
    }

    /** @param array<string, mixed> $queueConfig */
    private function queue(array $queueConfig): Queue
    {
        return new Queue(new Repository(['queue' => $queueConfig]), $this->basePath);
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $scanned = scandir($path);
        $items = $scanned === false ? [] : $scanned;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->deleteTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}

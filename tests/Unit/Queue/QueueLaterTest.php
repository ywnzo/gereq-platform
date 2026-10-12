<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Queue;

use Gereq\Config\Repository;
use Gereq\Queue\Queue;
use Gereq\Testing\TestCase;

final class QueueLaterTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . '/gereq-queue-later-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/storage/tmp/queue', 0775, true);
        mkdir($this->basePath . '/storage/tmp/queue-delayed', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->basePath);
        parent::tearDown();
    }

    public function testLaterStoresInDelayedUntilDue(): void
    {
        $queue = $this->queue(['driver' => 'file']);
        $job = new QueueTestJob('later');

        $queue->later($job, time() + 120);
        self::assertSame(0, $queue->pending());
        self::assertSame(1, $queue->delayed());
        self::assertNull($queue->popRaw());
        self::assertFalse($job->handled);
    }

    public function testLaterPromotesWhenDue(): void
    {
        $queue = $this->queue(['driver' => 'file']);
        $job = new QueueTestJob('due');

        $queue->later($job, time() - 1);
        self::assertSame(1, $queue->pending());
        self::assertSame(0, $queue->delayed());

        $payload = $queue->popRaw();
        self::assertIsString($payload);
        $restored = $queue->unserializePayload($payload);
        self::assertInstanceOf(QueueTestJob::class, $restored);
        self::assertSame('due', $restored->value);
    }

    public function testSyncLaterRunsInline(): void
    {
        $queue = $this->queue(['driver' => 'sync']);
        $job = new QueueTestJob('sync-later');
        $queue->later($job, 60);
        self::assertTrue($job->handled);
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
        $items = scandir($path);
        foreach ($items === false ? [] : $items as $item) {
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

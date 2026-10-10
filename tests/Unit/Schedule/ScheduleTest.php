<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Gereq\Config\Repository;
use Gereq\Queue\Queue;
use Gereq\Schedule\Schedule;
use Gereq\Schedule\ScheduleLock;
use Gereq\Schedule\ScheduleRunner;
use Gereq\Schedule\ScheduleState;
use Gereq\Testing\TestCase;
use Gereq\Tests\Unit\Queue\QueueTestJob;

final class ScheduleTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . '/gereq-schedule-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/storage/tmp/queue', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->basePath);
        parent::tearDown();
    }

    public function testJobEventDispatchesToQueueWhenDue(): void
    {
        $schedule = new Schedule();
        $job = new QueueTestJob('scheduled');
        $schedule->job($job)->everyMinute()->name('test.job');

        $queue = new Queue(new Repository(['queue' => ['driver' => 'file']]), $this->basePath);
        $runner = $this->runner($schedule, $queue);

        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $result = $runner->run($at);

        self::assertSame(1, $result['ran']);
        self::assertSame(1, $queue->pending());
        self::assertFalse($job->handled);
    }

    public function testCallEventRunsInline(): void
    {
        $schedule = new Schedule();
        $ran = false;
        $schedule->call(static function () use (&$ran): void {
            $ran = true;
        })->everyMinute();

        $queue = new Queue(new Repository(['queue' => ['driver' => 'file']]), $this->basePath);
        $this->runner($schedule, $queue)->run(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        self::assertTrue($ran);
    }

    public function testPausedEventIsSkippedButRunNowStillWorks(): void
    {
        $schedule = new Schedule();
        $job = new QueueTestJob('paused');
        $schedule->job($job)->everyMinute()->name('test.paused');

        $queue = new Queue(new Repository(['queue' => ['driver' => 'file']]), $this->basePath);
        $state = new ScheduleState($this->basePath);
        $state->pause('test.paused');
        $runner = new ScheduleRunner(
            $schedule,
            new ScheduleLock($this->basePath),
            $state,
            $queue,
        );

        $tick = $runner->run(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        self::assertSame(0, $tick['ran']);
        self::assertSame(1, $tick['skipped']);
        self::assertSame(0, $queue->pending());

        $manual = $runner->runNow('test.paused');
        self::assertSame(1, $manual['ran']);
        self::assertSame(1, $queue->pending());
    }

    private function runner(Schedule $schedule, Queue $queue): ScheduleRunner
    {
        return new ScheduleRunner(
            $schedule,
            new ScheduleLock($this->basePath),
            new ScheduleState($this->basePath),
            $queue,
        );
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->deleteTree($full) : unlink($full);
        }
        rmdir($path);
    }
}

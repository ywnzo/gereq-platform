<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\CarService;

use Modules\CarService\Domain\ServiceJob;
use PHPUnit\Framework\TestCase;

final class ServiceJobTest extends TestCase
{
    public function testCompleteCapsDurationAndStopsRunning(): void
    {
        $job = new ServiceJob('order-1', 'work');
        self::assertTrue($job->isRunning());

        $started = new \ReflectionProperty($job, 'startedAt');
        $started->setValue($job, new \DateTimeImmutable('2026-01-01 10:00:00'));

        $job->complete(new \DateTimeImmutable('2026-01-01 12:00:00'), 3600);
        self::assertFalse($job->isRunning());
        self::assertSame(3600, $job->durationSeconds());

        $job->complete(new \DateTimeImmutable('2026-01-01 15:00:00'));
        self::assertSame(3600, $job->durationSeconds());
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\BikeService;

use Modules\BikeService\Domain\BikeJob;
use PHPUnit\Framework\TestCase;

final class BikeJobTest extends TestCase
{
    public function testDurationMinutesAfterComplete(): void
    {
        $job = new BikeJob('order-1', 'diag');
        self::assertNull($job->durationMinutes());

        $started = new \ReflectionProperty($job, 'startedAt');
        $started->setValue($job, new \DateTimeImmutable('2026-01-01 10:00:00'));

        $finished = new \ReflectionProperty($job, 'finishedAt');
        $job->complete();
        $finished->setValue($job, new \DateTimeImmutable('2026-01-01 10:45:00'));

        self::assertSame(45, $job->durationMinutes());
    }
}

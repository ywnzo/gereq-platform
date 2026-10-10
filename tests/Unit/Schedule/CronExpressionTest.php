<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Gereq\Schedule\CronExpression;
use Gereq\Testing\TestCase;

final class CronExpressionTest extends TestCase
{
    public function testEveryFiveMinutes(): void
    {
        $cron = new CronExpression('*/5 * * * *');
        $tz = new DateTimeZone('UTC');

        self::assertTrue($cron->isDue(new DateTimeImmutable('2026-10-10 12:00:00', $tz)));
        self::assertTrue($cron->isDue(new DateTimeImmutable('2026-10-10 12:05:00', $tz)));
        self::assertFalse($cron->isDue(new DateTimeImmutable('2026-10-10 12:07:00', $tz)));
    }

    public function testDailyAt(): void
    {
        $cron = new CronExpression('0 7 * * *');
        $tz = new DateTimeZone('Europe/Prague');

        self::assertTrue($cron->isDue(new DateTimeImmutable('2026-10-10 07:00:00', $tz)));
        self::assertFalse($cron->isDue(new DateTimeImmutable('2026-10-10 07:01:00', $tz)));
    }

    public function testNextRun(): void
    {
        $cron = new CronExpression('*/15 * * * *');
        $tz = new DateTimeZone('UTC');
        $next = $cron->nextRun(new DateTimeImmutable('2026-10-10 12:01:00', $tz), $tz);

        self::assertNotNull($next);
        self::assertSame('2026-10-10 12:15:00', $next->format('Y-m-d H:i:s'));
    }
}

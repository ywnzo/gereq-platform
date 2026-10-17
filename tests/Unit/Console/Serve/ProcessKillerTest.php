<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Console\Serve;

use Gereq\Console\Serve\ProcessKiller;
use Gereq\Testing\TestCase;

final class ProcessKillerTest extends TestCase
{
    public function testForceKillsBackgroundProcessQuickly(): void
    {
        $pid = (int) trim((string) shell_exec('setsid sleep 120 >/dev/null 2>&1 & echo $!'));
        self::assertGreaterThan(1, $pid);
        self::assertTrue(ProcessKiller::alive($pid));

        $t = microtime(true);
        ProcessKiller::force($pid);
        $ms = (microtime(true) - $t) * 1000;

        self::assertFalse(ProcessKiller::alive($pid));
        self::assertLessThan(500, $ms, 'force() must not block for long');
    }

    public function testAliveIsFalseForMissingPid(): void
    {
        self::assertFalse(ProcessKiller::alive(2_147_483_646));
    }
}

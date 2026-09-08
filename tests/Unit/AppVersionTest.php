<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Support\AppVersion;
use PHPUnit\Framework\TestCase;

final class AppVersionTest extends TestCase
{
    protected function tearDown(): void
    {
        AppVersion::clearCache();
        parent::tearDown();
    }

    public function testReadsVersionFile(): void
    {
        $dir = sys_get_temp_dir() . '/gereq-version-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/VERSION', "1.2.3\n");

        try {
            self::assertSame('1.2.3', AppVersion::current($dir));
        } finally {
            unlink($dir . '/VERSION');
            rmdir($dir);
        }
    }

    public function testNormalizesVPrefix(): void
    {
        self::assertSame('2.0.0', AppVersion::normalize('v2.0.0'));
        self::assertTrue(AppVersion::isSemver('v1.0.0'));
        self::assertFalse(AppVersion::isSemver('latest'));
    }

    public function testIsNewerThan(): void
    {
        self::assertTrue(AppVersion::isNewerThan('1.1.0', '1.0.0'));
        self::assertFalse(AppVersion::isNewerThan('1.0.0', '1.0.0'));
        self::assertFalse(AppVersion::isNewerThan('0.9.0', '1.0.0'));
    }

    public function testFallsBackWhenMissing(): void
    {
        $dir = sys_get_temp_dir() . '/gereq-version-missing-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            self::assertSame(AppVersion::DEFAULT, AppVersion::current($dir));
        } finally {
            rmdir($dir);
        }
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Env;

use Gereq\Env\CliEnvFile;
use PHPUnit\Framework\TestCase;

final class CliEnvFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        CliEnvFile::reset();
        $this->tmpDir = sys_get_temp_dir() . '/gereq-cli-env-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
        file_put_contents($this->tmpDir . '/.env', "APP_ENV=local\n");
        file_put_contents($this->tmpDir . '/.env.production', "APP_ENV=production\n");
    }

    protected function tearDown(): void
    {
        CliEnvFile::reset();
        foreach (glob($this->tmpDir . '/{.*,*}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testDefaultsToDotEnvAndLeavesArgvIntact(): void
    {
        $argv = ['gereq', 'about', '-v'];
        $path = CliEnvFile::resolve($this->tmpDir, $argv);

        self::assertSame($this->tmpDir . '/.env', $path);
        self::assertSame(['gereq', 'about', '-v'], $argv);
        self::assertSame($path, CliEnvFile::resolvedPath());
    }

    public function testStripsConfigEnvEqualsForm(): void
    {
        $argv = ['gereq', 'config.env=.env.production', 'about'];
        $path = CliEnvFile::resolve($this->tmpDir, $argv);

        self::assertSame($this->tmpDir . '/.env.production', $path);
        self::assertSame(['gereq', 'about'], $argv);
    }

    public function testStripsLongOptionForm(): void
    {
        $argv = ['gereq', '--config.env=.env.production', 'migrate'];
        $path = CliEnvFile::resolve($this->tmpDir, $argv);

        self::assertSame($this->tmpDir . '/.env.production', $path);
        self::assertSame(['gereq', 'migrate'], $argv);
    }

    public function testAbsolutePathIsKept(): void
    {
        $abs = $this->tmpDir . '/.env.production';
        $argv = ['gereq', 'config.env=' . $abs, 'about'];
        $path = CliEnvFile::resolve($this->tmpDir, $argv);

        self::assertSame($abs, $path);
    }

    public function testMissingExplicitFileThrows(): void
    {
        $argv = ['gereq', 'config.env=.env.missing', 'about'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Env file not found');
        CliEnvFile::resolve($this->tmpDir, $argv);
    }

    public function testAbsolutePathHelperKeepsDotEnvBasename(): void
    {
        self::assertSame(
            '/app/.env',
            CliEnvFile::absolutePath('/app', '.env'),
        );
        self::assertSame(
            '/app/.env.production',
            CliEnvFile::absolutePath('/app', './.env.production'),
        );
    }
}

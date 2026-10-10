<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Database;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\PasswordHasher;
use Gereq\Database\DemoSeeder;
use PHPUnit\Framework\TestCase;

final class DemoSeederProductionGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        parent::tearDown();
    }

    public function testSeedThrowsInProduction(): void
    {
        $seeder = new DemoSeeder(
            $this->createMock(EntityManagerInterface::class),
            new PasswordHasher(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Demo seed is disabled when APP_ENV=production.');

        $seeder->seed();
    }

    public function testDemoPasswordConstantRemoved(): void
    {
        self::assertFalse(defined(DemoSeeder::class . '::DEMO_PASSWORD'));
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Database;

use Doctrine\DBAL\DriverManager;
use Gereq\Config\Repository;
use Gereq\Database\DatabaseCreator;
use Gereq\Testing\TestCase;

final class DatabaseCreatorSqliteNoopTest extends TestCase
{
    public function testSqliteIsNoop(): void
    {
        $creator = new DatabaseCreator(new Repository([
            'database' => [
                'connection' => 'sqlite',
                'name' => 'ignored',
            ],
        ]));

        self::assertFalse($creator->ensureExists());
    }

    public function testRejectsUnsafeDatabaseName(): void
    {
        $creator = new DatabaseCreator(new Repository([
            'database' => [
                'connection' => 'pgsql',
                'name' => 'evil;drop',
                'host' => '127.0.0.1',
                'port' => 5432,
                'username' => 'x',
                'password' => 'y',
            ],
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $creator->ensureExists();
    }
}

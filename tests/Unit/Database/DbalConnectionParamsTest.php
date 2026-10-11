<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Database;

use Gereq\Config\Repository;
use Gereq\Database\DbalConnectionParams;
use PHPUnit\Framework\TestCase;

final class DbalConnectionParamsTest extends TestCase
{
    public function testPgsqlIncludesConnectTimeout(): void
    {
        $config = new Repository([
            'database' => [
                'connection' => 'pgsql',
                'host' => 'postgres',
                'port' => 5432,
                'name' => 'gereq_v2',
                'username' => 'gereq',
                'password' => 'secret',
            ],
        ]);

        $params = DbalConnectionParams::forConfig($config, '/tmp');

        self::assertSame('pdo_pgsql', $params['driver']);
        self::assertSame(3, $params['connect_timeout']);
    }
}

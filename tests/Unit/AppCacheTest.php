<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Cache\AppCache;
use Gereq\Config\Repository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AppCacheTest extends TestCase
{
    public function testRememberStoresAndReturns(): void
    {
        $cache = new AppCache(new ArrayAdapter(), new Repository([
            'cache' => ['default_ttl' => 60],
        ]));

        $calls = 0;
        $a = $cache->remember('k1', static function () use (&$calls): string {
            $calls++;

            return 'hello';
        });
        $b = $cache->remember('k1', static function () use (&$calls): string {
            $calls++;

            return 'noop';
        });

        self::assertSame('hello', $a);
        self::assertSame('hello', $b);
        self::assertSame(1, $calls);
    }

    public function testForgetRemoves(): void
    {
        $cache = new AppCache(new ArrayAdapter(), new Repository([
            'cache' => ['default_ttl' => 60],
        ]));
        $cache->put('x', 'y');
        self::assertSame('y', $cache->get('x'));
        $cache->forget('x');
        self::assertNull($cache->get('x'));
    }
}

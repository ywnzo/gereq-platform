<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Module;

use Gereq\Module\ModuleFootprintCache;
use PHPUnit\Framework\TestCase;

final class ModuleFootprintCacheTest extends TestCase
{
    public function testWriteReadAndForget(): void
    {
        $root = sys_get_temp_dir() . '/gereq-fp-cache-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/framework/module-footprints', 0775, true);
        $cache = new ModuleFootprintCache($root);

        self::assertNull($cache->read('demo'));
        $cache->write('demo', 'quick-abc', str_repeat('a', 64));
        $hit = $cache->read('demo');
        self::assertNotNull($hit);
        self::assertSame('quick-abc', $hit['quick']);
        self::assertSame(str_repeat('a', 64), $hit['footprint']);

        $cache->forget('demo');
        self::assertNull($cache->read('demo'));
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Module;

use Gereq\Module\ModuleDiscovery;
use PHPUnit\Framework\TestCase;

final class ModuleDiscoveryMemoTest extends TestCase
{
    protected function tearDown(): void
    {
        ModuleDiscovery::clearMemo();
        parent::tearDown();
    }

    public function testDiscoverReturnsSameInstanceMapFromMemo(): void
    {
        $root = dirname(__DIR__, 3);
        ModuleDiscovery::clearMemo();
        $first = ModuleDiscovery::discover($root, false);
        $second = ModuleDiscovery::discover($root, false);
        self::assertSame($first, $second);
        self::assertNotSame([], $first);
    }

    public function testClearMemoForcesRescan(): void
    {
        $root = dirname(__DIR__, 3);
        ModuleDiscovery::clearMemo();
        $first = ModuleDiscovery::discover($root, false);
        ModuleDiscovery::clearMemo();
        $second = ModuleDiscovery::discover($root, false);
        self::assertEquals($first, $second);
        self::assertNotSame($first, $second);
    }
}

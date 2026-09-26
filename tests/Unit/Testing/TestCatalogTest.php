<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Testing;

use Gereq\Testing\TestCatalog;
use Gereq\Testing\TestEngine;
use Gereq\Testing\TestTargetKind;
use PHPUnit\Framework\TestCase;

final class TestCatalogTest extends TestCase
{
    private TestCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new TestCatalog(dirname(__DIR__, 3));
    }

    public function testResolveAllAndCore(): void
    {
        $all = $this->catalog->resolve('all');
        self::assertSame(TestTargetKind::All, $all->kind);
        self::assertNotEmpty($all->paths);

        $core = $this->catalog->resolve('core');
        self::assertSame(TestTargetKind::Core, $core->kind);
        self::assertTrue($core->hasTests());
        foreach ($core->paths as $path) {
            self::assertStringNotContainsString('/tests/Unit/Modules', $path);
        }
    }

    public function testResolveModulesAggregate(): void
    {
        $modules = $this->catalog->resolve('modules');
        self::assertSame(TestTargetKind::Modules, $modules->kind);
        self::assertTrue($modules->hasTests());
        self::assertContains(
            dirname(__DIR__, 3) . '/modules/Shop/Testing',
            $modules->paths,
        );
        self::assertContains(
            dirname(__DIR__, 3) . '/tests/Unit/Modules/ModuleStructure',
            $modules->paths,
        );
    }

    public function testResolveSingleModuleByIdAndDir(): void
    {
        $byId = $this->catalog->resolve('module:shop');
        self::assertSame(TestTargetKind::Module, $byId->kind);
        self::assertSame('shop', $byId->packageId);
        self::assertTrue($byId->hasTests());
        self::assertSame(
            [dirname(__DIR__, 3) . '/modules/Shop/Testing'],
            $byId->paths,
        );

        $byBare = $this->catalog->resolve('Shop');
        self::assertSame('module:shop', $byBare->id);
        self::assertSame($byId->paths, $byBare->paths);
    }

    public function testResolveModuleIncludesShippedTestsWhenPresent(): void
    {
        $gereq = $this->catalog->resolve('gereq');
        $shipped = dirname(__DIR__, 3) . '/modules/Gereq/Testing';
        self::assertContains($shipped, $gereq->paths);
        self::assertTrue($gereq->hasTests());
    }

    public function testResolveUnknownThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->resolve('definitely-not-a-module-xyz');
    }

    public function testEngineListIncludesCore(): void
    {
        $engine = new TestEngine(dirname(__DIR__, 3));
        $ids = array_map(static fn ($t) => $t->id, $engine->listTargets());
        self::assertContains('core', $ids);
        self::assertContains('modules', $ids);
        self::assertContains('file', $ids);
    }
}

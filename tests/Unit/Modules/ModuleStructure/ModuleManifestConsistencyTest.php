<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\ModuleStructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Smoke: každý modul má manifest a Module třídu se shodnou verzí.
 */
final class ModuleManifestConsistencyTest extends TestCase
{
    /** @return list<array{0: string}> */
    public static function moduleDirs(): array
    {
        $root = dirname(__DIR__, 4) . '/modules';
        $out = [];
        foreach (\Gereq\Module\ModuleDiscovery::discover(dirname($root)) as $manifest) {
            if (!$manifest->isIntegration()) {
                $out[] = [substr($manifest->path, strlen($root) + 1)];
            }
        }
        sort($out);

        return $out;
    }

    #[DataProvider('moduleDirs')]
    public function testModuleManifestAndVersionAlign(string $moduleDir): void
    {
        $path = dirname(__DIR__, 4) . '/modules/' . $moduleDir . '/module.php';
        /** @var array<string, mixed> $manifest */
        $manifest = require $path;

        self::assertIsArray($manifest);
        self::assertNotEmpty($manifest['id'] ?? null, $moduleDir . ': chybí id');
        self::assertNotEmpty($manifest['version'] ?? null, $moduleDir . ': chybí version');
        self::assertNotEmpty($manifest['class'] ?? null, $moduleDir . ': chybí class');

        $class = (string) $manifest['class'];
        self::assertTrue(class_exists($class), $moduleDir . ': třída ' . $class . ' neexistuje');

        $module = new $class();
        self::assertTrue(method_exists($module, 'version'));
        self::assertTrue(method_exists($module, 'id'));
        self::assertSame(
            (string) $manifest['version'],
            (string) $module->version(),
            $moduleDir . ': version() nesedí s module.php',
        );
        self::assertSame(
            (string) $manifest['id'],
            (string) $module->id(),
            $moduleDir . ': id() nesedí s module.php',
        );
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Module;

use Gereq\Module\ModuleAssetBuilder;
use PHPUnit\Framework\TestCase;

final class ModuleAssetBuilderTest extends TestCase
{
    public function testEnsureNoopWhenModuleHasNoAssetSources(): void
    {
        $calls = 0;
        $builder = new ModuleAssetBuilder(
            sys_get_temp_dir(),
            static function () use (&$calls): array {
                ++$calls;

                return ['code' => 0, 'output' => ''];
            },
        );

        $builder->ensure('definitely-missing-module-xyz');
        self::assertSame(0, $calls);
    }

    public function testEnsureRunsBunWhenMissingAndSourcesExist(): void
    {
        $root = $this->scratchModule('demo', withJs: true, withCss: true);
        // build-module.ts must exist for ensure() path check
        mkdir($root . '/scripts', 0775, true);
        file_put_contents($root . '/scripts/build-module.ts', "// stub\n");

        $commands = [];
        $builder = new ModuleAssetBuilder(
            $root,
            static function (string $cwd, array $command) use (&$commands, $root): array {
                $commands[] = [$cwd, $command];
                if (($command[0] ?? '') === 'bash') {
                    return ['code' => 0, 'output' => ''];
                }
                mkdir($root . '/public/build/assets', 0775, true);
                file_put_contents(
                    $root . '/public/build/manifest.json',
                    json_encode([
                        'module:demo' => [
                            'file' => 'assets/module-demo.js',
                            'css' => ['assets/module-demo.css'],
                            'moduleId' => 'demo',
                        ],
                    ], JSON_THROW_ON_ERROR),
                );
                file_put_contents($root . '/public/build/assets/module-demo.js', '// ok');

                return ['code' => 0, 'output' => 'ok'];
            },
        );

        self::assertTrue($builder->hasSources('demo'));
        self::assertFalse($builder->isBuilt('demo'));
        $builder->ensure('demo');
        self::assertTrue($builder->isBuilt('demo'));
        self::assertGreaterThanOrEqual(2, count($commands));
    }

    public function testEnsureSkipsWhenAlreadyBuiltUnlessForced(): void
    {
        $root = $this->scratchModule('demo', withJs: true, withCss: false);
        mkdir($root . '/scripts', 0775, true);
        file_put_contents($root . '/scripts/build-module.ts', "// stub\n");
        mkdir($root . '/public/build/assets', 0775, true);
        file_put_contents(
            $root . '/public/build/manifest.json',
            json_encode([
                'module:demo' => [
                    'file' => 'assets/module-demo.js',
                    'css' => [],
                    'moduleId' => 'demo',
                ],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents($root . '/public/build/assets/module-demo.js', '// ok');
        // Built file must be newer than sources so isStale() is false.
        $built = $root . '/public/build/assets/module-demo.js';
        $source = $root . '/modules/Demo/Resources/assets/js/admin.ts';
        touch($source, time() - 10);
        touch($built, time());

        $builds = 0;
        $builder = new ModuleAssetBuilder(
            $root,
            static function (string $cwd, array $command) use (&$builds): array {
                if (($command[0] ?? '') === 'bash') {
                    return ['code' => 0, 'output' => ''];
                }
                ++$builds;

                return ['code' => 0, 'output' => ''];
            },
        );

        self::assertFalse($builder->isStale('demo'));
        $builder->ensure('demo');
        self::assertSame(0, $builds);
        $builder->ensure('demo', force: true);
        self::assertSame(1, $builds);
    }

    public function testEnsureRebuildsWhenSourcesNewerThanBuilt(): void
    {
        $root = $this->scratchModule('demo', withJs: true, withCss: false);
        mkdir($root . '/scripts', 0775, true);
        file_put_contents($root . '/scripts/build-module.ts', "// stub\n");
        mkdir($root . '/public/build/assets', 0775, true);
        file_put_contents(
            $root . '/public/build/manifest.json',
            json_encode([
                'module:demo' => [
                    'file' => 'assets/module-demo.js',
                    'css' => [],
                    'moduleId' => 'demo',
                ],
            ], JSON_THROW_ON_ERROR),
        );
        $built = $root . '/public/build/assets/module-demo.js';
        file_put_contents($built, '// old');
        touch($built, time() - 30);
        touch($root . '/modules/Demo/Resources/assets/js/admin.ts', time());

        $builds = 0;
        $builder = new ModuleAssetBuilder(
            $root,
            static function (string $cwd, array $command) use (&$builds, $built): array {
                if (($command[0] ?? '') === 'bash') {
                    return ['code' => 0, 'output' => ''];
                }
                ++$builds;
                file_put_contents($built, '// rebuilt');
                touch($built, time() + 1);

                return ['code' => 0, 'output' => ''];
            },
        );

        self::assertTrue($builder->isStale('demo'));
        $builder->ensure('demo');
        self::assertSame(1, $builds);
        self::assertFalse($builder->isStale('demo'));
    }

    public function testTryEnsureReturnsErrorMessageInsteadOfThrowing(): void
    {
        $root = $this->scratchModule('demo', withJs: false, withCss: true);
        mkdir($root . '/scripts', 0775, true);
        file_put_contents($root . '/scripts/build-module.ts', "// stub\n");
        $builder = new ModuleAssetBuilder(
            $root,
            static function (): array {
                return ['code' => 1, 'output' => ''];
            },
        );

        $error = $builder->tryEnsure('demo');
        self::assertIsString($error);
        self::assertStringContainsString('bun is not available', $error);
    }

    public function testRemoveIsIdempotentForCssOnlyManifest(): void
    {
        $root = $this->scratchModule('demo', withJs: false, withCss: true);
        mkdir($root . '/public/build/assets', 0775, true);
        file_put_contents($root . '/public/build/assets/module-demo.css', '/* css */');
        file_put_contents(
            $root . '/public/build/manifest.json',
            json_encode([
                'module:demo' => [
                    'file' => 'assets/module-demo.css',
                    'css' => ['assets/module-demo.css'],
                    'moduleId' => 'demo',
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $builder = new ModuleAssetBuilder($root);
        $builder->remove('demo');
        $builder->remove('demo');

        $manifest = json_decode((string) file_get_contents($root . '/public/build/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertArrayNotHasKey('module:demo', $manifest);
        self::assertFileDoesNotExist($root . '/public/build/assets/module-demo.css');
    }

    public function testEnsureThrowsWhenBunMissing(): void
    {
        $root = $this->scratchModule('demo', withJs: false, withCss: true);
        mkdir($root . '/scripts', 0775, true);
        file_put_contents($root . '/scripts/build-module.ts', "// stub\n");
        $builder = new ModuleAssetBuilder(
            $root,
            static function (): array {
                return ['code' => 1, 'output' => ''];
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bun is not available');
        $builder->ensure('demo');
    }

    public function testRemoveDeletesFilesAndManifestEntry(): void
    {
        $root = $this->scratchModule('demo', withJs: true, withCss: true);
        mkdir($root . '/public/build/assets', 0775, true);
        file_put_contents($root . '/public/build/assets/module-demo-abc.min.js', '// js');
        file_put_contents($root . '/public/build/assets/module-demo-abc.min.css', '/* css */');
        file_put_contents($root . '/public/build/assets/module-demo-old.min.css', '/* stale */');
        file_put_contents(
            $root . '/public/build/manifest.json',
            json_encode([
                'resources/js/admin.ts' => [
                    'file' => 'assets/admin.min.js',
                    'css' => ['assets/admin.min.css'],
                ],
                'module:demo' => [
                    'file' => 'assets/module-demo-abc.min.js',
                    'css' => ['assets/module-demo-abc.min.css'],
                    'moduleId' => 'demo',
                ],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        $builder = new ModuleAssetBuilder($root);
        $builder->remove('demo');

        self::assertFileDoesNotExist($root . '/public/build/assets/module-demo-abc.min.js');
        self::assertFileDoesNotExist($root . '/public/build/assets/module-demo-abc.min.css');
        self::assertFileDoesNotExist($root . '/public/build/assets/module-demo-old.min.css');

        $manifest = json_decode((string) file_get_contents($root . '/public/build/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('resources/js/admin.ts', $manifest);
        self::assertArrayNotHasKey('module:demo', $manifest);
    }

    private function scratchModule(string $id, bool $withJs, bool $withCss): string
    {
        $root = sys_get_temp_dir() . '/gereq-assets-' . bin2hex(random_bytes(4));
        $modDir = $root . '/modules/Demo';
        mkdir($modDir . '/Resources/assets/js', 0775, true);
        mkdir($modDir . '/Resources/assets/css', 0775, true);
        file_put_contents($modDir . '/module.php', <<<PHP
<?php

declare(strict_types=1);

return [
    'id' => '{$id}',
    'name' => 'Demo',
    'version' => '1.0.0',
    'description' => 'test',
    'class' => 'Modules\\\\Demo\\\\DemoModule',
    'requires' => [],
    'permissions' => [],
];
PHP);
        if ($withJs) {
            file_put_contents($modDir . '/Resources/assets/js/admin.ts', "export default function register() {}\n");
        }
        if ($withCss) {
            file_put_contents($modDir . '/Resources/assets/css/admin.css', "/* test */\n");
        }

        return $root;
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\View;

use Gereq\Config\Repository;
use Gereq\View\RenderEngine;
use PHPUnit\Framework\TestCase;

final class RenderEngineTest extends TestCase
{
    public function testSameOriginUrlsFromManifest(): void
    {
        $dir = $this->scratch();
        $this->writeManifest($dir, [
            'resources/js/admin.ts' => [
                'file' => 'assets/admin-abc.js',
                'css' => ['assets/admin-abc.css'],
            ],
        ]);

        $engine = new RenderEngine($dir, $this->config());

        self::assertSame('/build/assets/admin-abc.js', $engine->url('admin'));
        self::assertSame('/build/assets/admin-abc.css', $engine->url('css'));
        self::assertStringContainsString('href="/build/assets/admin-abc.css"', $engine->cssTags());
        self::assertStringContainsString('src="/build/assets/admin-abc.js"', $engine->scriptTags('admin', 'n1'));
        self::assertStringContainsString('nonce="n1"', $engine->scriptTags('admin', 'n1'));
        self::assertSame('', $engine->reloadTags());
        self::assertSame([], $engine->cspOrigins());
    }

    public function testCssTagsIncludeOnlyEnabledModuleStyles(): void
    {
        $dir = $this->scratch();
        $this->writeManifest($dir, [
            'resources/js/admin.ts' => [
                'file' => 'assets/admin.js',
                'css' => ['assets/admin.css'],
            ],
            'module:bikeservice' => [
                'file' => 'assets/module-bikeservice.css',
                'css' => ['assets/module-bikeservice.css'],
                'moduleId' => 'bikeservice',
            ],
            'module:fleet' => [
                'file' => 'assets/module-fleet.css',
                'css' => ['assets/module-fleet.css'],
                'moduleId' => 'fleet',
            ],
        ]);

        $modules = new class (['bikeservice']) implements \Gereq\Module\EnabledModulesProvider {
            /** @param list<string> $ids */
            public function __construct(private readonly array $ids)
            {
            }

            public function enabledIds(): array
            {
                return $this->ids;
            }
        };

        $engine = new RenderEngine($dir, $this->config(), $modules);
        $tags = $engine->cssTags();

        self::assertStringContainsString('href="/build/assets/admin.css"', $tags);
        self::assertStringContainsString('href="/build/assets/module-bikeservice.css"', $tags);
        self::assertStringNotContainsString('module-fleet.css', $tags);
    }

    public function testScriptTagsBootstrapOnlyEnabledModuleJs(): void
    {
        $dir = $this->scratch();
        $this->writeManifest($dir, [
            'resources/js/admin.ts' => [
                'file' => 'assets/admin.js',
                'css' => ['assets/admin.css'],
            ],
            'module:cms' => [
                'file' => 'assets/module-cms.js',
                'css' => ['assets/module-cms.css'],
                'moduleId' => 'cms',
            ],
            'module:fleet' => [
                'file' => 'assets/module-fleet.js',
                'css' => ['assets/module-fleet.css'],
                'moduleId' => 'fleet',
            ],
            'module:vitality' => [
                'file' => 'assets/module-vitality.css',
                'css' => ['assets/module-vitality.css'],
                'moduleId' => 'vitality',
            ],
        ]);

        $modules = new class (['cms', 'vitality']) implements \Gereq\Module\EnabledModulesProvider {
            /** @param list<string> $ids */
            public function __construct(private readonly array $ids)
            {
            }

            public function enabledIds(): array
            {
                return $this->ids;
            }
        };

        $engine = new RenderEngine($dir, $this->config(), $modules);
        self::assertSame(['/build/assets/module-cms.js'], $engine->enabledModuleJsUrls());
        $tags = $engine->scriptTags('admin', 'n1');
        self::assertStringContainsString('window.__GEREQ_MODULE_ASSETS__=["/build/assets/module-cms.js"]', $tags);
        self::assertStringNotContainsString('module-fleet.js', $tags);
        self::assertStringContainsString('src="/build/assets/admin.js"', $tags);
    }

    public function testAssetOriginAndPrefixAndCsp(): void
    {
        $dir = $this->scratch();
        $this->writeManifest($dir, [
            'resources/js/admin.ts' => [
                'file' => 'assets/admin.js',
                'css' => ['assets/admin.css'],
            ],
        ]);

        $engine = new RenderEngine($dir, $this->config([
            'asset_origin' => 'https://cdn.example.test',
            'url_prefix' => '/shop',
            'csp_origins' => ['https://fonts.example.test'],
        ]));

        self::assertSame('https://cdn.example.test/shop/build/assets/admin.js', $engine->url('admin'));
        self::assertSame(
            ['https://cdn.example.test', 'https://fonts.example.test'],
            $engine->cspOrigins(),
        );
    }

    public function testFallsBackToAppUrlPrefixWhenRenderPrefixEmpty(): void
    {
        $dir = $this->scratch();
        $this->writeManifest($dir, [
            'resources/js/admin.ts' => [
                'file' => 'assets/admin.js',
                'css' => ['assets/admin.css'],
            ],
        ]);

        $engine = new RenderEngine($dir, new Repository([
            'app' => ['url_prefix' => '/realityV3'],
            'render' => [
                'asset_origin' => '',
                'url_prefix' => '',
                'public_path' => '/build',
                'manifest' => 'public/build/manifest.json',
                'dev_marker' => 'public/build/.dev',
                'entries' => [
                    'admin' => 'resources/js/admin.ts',
                    'css' => 'resources/css/kernel.css',
                ],
                'csp_origins' => [],
            ],
        ]));

        self::assertSame('/realityV3/build/assets/admin.js', $engine->url('admin'));
    }

    public function testWatchModeCacheBustAndReload(): void
    {
        $dir = $this->scratch();
        $this->writeManifest($dir, [
            'resources/js/admin.ts' => [
                'file' => 'assets/admin.js',
                'css' => ['assets/admin.css'],
            ],
        ]);
        file_put_contents($dir . '/public/build/.dev', '{"mode":"watch"}');

        $engine = new RenderEngine($dir, $this->config(['watch' => ['reload' => true, 'poll_ms' => 500]]));

        self::assertTrue($engine->isWatch());
        self::assertMatchesRegularExpression('#/build/assets/admin\.js\?v=\d+#', $engine->url('admin'));
        $reload = $engine->reloadTags('abc');
        self::assertStringContainsString('nonce="abc"', $reload);
        self::assertStringContainsString('/build/watch.json', $reload);
        self::assertStringContainsString('rehydrateCss', $reload);
        self::assertStringContainsString('viewsAt', $reload);
        self::assertStringContainsString('asset watch connected', $reload);
    }

    /**
     * @param array<string, mixed> $render
     */
    private function config(array $render = []): Repository
    {
        return new Repository([
            'app' => ['url_prefix' => ''],
            'render' => array_merge([
                'asset_origin' => '',
                'url_prefix' => '',
                'public_path' => '/build',
                'manifest' => 'public/build/manifest.json',
                'dev_marker' => 'public/build/.dev',
                'watch_state' => 'public/build/watch.json',
                'watch' => ['reload' => true, 'poll_ms' => 1200, 'probe_when_debug' => true],
                'entries' => [
                    'admin' => 'resources/js/admin.ts',
                    'css' => 'resources/css/kernel.css',
                ],
                'csp_origins' => [],
            ], $render),
        ]);
    }

    public function testDebugProbeInjectsReloadWithoutWatchMarker(): void
    {
        $dir = $this->scratch();
        mkdir($dir . '/public/build', 0775, true);

        $engine = new RenderEngine($dir, new Repository([
            'app' => ['url_prefix' => '', 'debug' => true],
            'render' => [
                'asset_origin' => '',
                'url_prefix' => '',
                'public_path' => '/build',
                'manifest' => 'public/build/manifest.json',
                'dev_marker' => 'public/build/.dev',
                'watch_state' => 'public/build/watch.json',
                'watch' => ['reload' => true, 'poll_ms' => 1200, 'probe_when_debug' => true],
                'entries' => [
                    'admin' => 'resources/js/admin.ts',
                    'css' => 'resources/css/kernel.css',
                ],
                'csp_origins' => [],
            ],
        ]));

        self::assertFalse($engine->isWatch());
        $reload = $engine->reloadTags();
        self::assertStringContainsString('/build/watch.json', $reload);
        self::assertStringContainsString('probeMs', $reload);
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(string $dir, array $manifest): void
    {
        mkdir($dir . '/public/build', 0775, true);
        file_put_contents(
            $dir . '/public/build/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
        );
    }

    private function scratch(): string
    {
        $dir = sys_get_temp_dir() . '/gereq-render-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);

        return $dir;
    }
}

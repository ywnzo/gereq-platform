<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Module;

use Gereq\Config\Repository;
use Gereq\Module\ModuleFootprint;
use Gereq\Module\ModuleVersionStore;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;

final class ModuleVersionStoreImmutableTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $this->removeTree($this->tmp);
        }
        parent::tearDown();
    }

    public function testInstallFromZipStoresFootprintAndRefusesDifferentContent(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gereq-ver-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/storage/modules', 0775, true);

        $store = $this->store();
        $zip1 = $this->makeZip('demo', '1.0.0', "v1\n");
        $path = $store->installFromZip('demo', '1.0.0', $zip1);
        $fp1 = ModuleFootprint::ofDirectory($path);
        self::assertSame($fp1, $store->footprint('demo', '1.0.0'));
        self::assertFileExists($path . '/' . ModuleFootprint::META_FILE);

        // Same content again — allowed (idempotent).
        $zip1b = $this->makeZip('demo', '1.0.0', "v1\n");
        self::assertSame($path, $store->installFromZip('demo', '1.0.0', $zip1b));

        $zip2 = $this->makeZip('demo', '1.0.0', "v2-changed\n");
        $this->expectException(\RuntimeException::class);
        $store->installFromZip('demo', '1.0.0', $zip2);
    }

    private function store(): ModuleVersionStore
    {
        return new ModuleVersionStore(
            $this->tmp,
            new Repository(['modules' => ['versions_path' => 'storage/modules']]),
            new PassthroughTranslator(),
        );
    }

    private function makeZip(string $id, string $version, string $modulePhpBody): string
    {
        $work = $this->tmp . '/zip-' . bin2hex(random_bytes(3));
        mkdir($work . '/pkg', 0775, true);
        file_put_contents($work . '/pkg/module.php', <<<PHP
<?php

return [
    'id' => '{$id}',
    'version' => '{$version}',
    'class' => 'Modules\\\\Demo\\\\DemoModule',
    'name' => 'Demo',
];
PHP);
        file_put_contents($work . '/pkg/payload.txt', $modulePhpBody);

        $zipPath = $work . '/archive.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE));
        $zip->addFile($work . '/pkg/module.php', 'pkg/module.php');
        $zip->addFile($work . '/pkg/payload.txt', 'pkg/payload.txt');
        $zip->close();

        return $zipPath;
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

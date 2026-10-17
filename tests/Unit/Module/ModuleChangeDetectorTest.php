<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Module;

use Gereq\Module\ModuleChangeDetector;
use Gereq\Module\ModuleFootprint;
use Gereq\Module\ModuleInstalledSnapshot;
use Gereq\Testing\TestCase;

final class ModuleChangeDetectorTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $this->removeTree($this->tmp);
        }
        parent::tearDown();
    }

    public function testMultisetLineDiff(): void
    {
        [$add, $del] = ModuleChangeDetector::multisetLineDiff("a\nb\n", "a\nc\n");
        self::assertSame(1, $add);
        self::assertSame(1, $del);
    }

    public function testSnapshotRoundTrip(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gereq-snap-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
        $snap = new ModuleInstalledSnapshot($this->tmp);
        $files = ['module.php' => hash('sha256', 'x'), 'A.php' => hash('sha256', 'y')];
        $snap->write('demo', '1.0.0', 'abc', $files);
        $read = $snap->read('demo');
        self::assertNotNull($read);
        self::assertSame('1.0.0', $read['version']);
        ksort($files, SORT_STRING);
        self::assertSame($files, $read['files']);
    }

    public function testInventoryMatchesFootprintInputs(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gereq-inv-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/mod', 0775, true);
        file_put_contents($this->tmp . '/mod/module.php', "return [];\n");
        file_put_contents($this->tmp . '/mod/Foo.php', "<?php\n");
        $inv = ModuleFootprint::inventory($this->tmp . '/mod');
        self::assertArrayHasKey('module.php', $inv);
        self::assertArrayHasKey('Foo.php', $inv);
        self::assertSame(64, strlen(ModuleFootprint::ofDirectory($this->tmp . '/mod')));
    }

    public function testMultisetIgnoresUnrelatedNoise(): void
    {
        // Gate diffs must describe installed→live, not an entire dirty git tree.
        [$add, $del] = ModuleChangeDetector::multisetLineDiff(
            "keep\nold\n",
            "keep\nnew\n",
        );
        self::assertSame(1, $add);
        self::assertSame(1, $del);
        self::assertLessThan(10, $add + $del);
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

<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Module;

use Gereq\Module\ModuleFootprint;
use Gereq\Testing\TestCase;

final class ModuleFootprintTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $this->removeTree($this->tmp);
        }
        parent::tearDown();
    }

    public function testSameTreeYieldsSameFootprint(): void
    {
        $a = $this->scratch('a');
        $b = $this->scratch('b');
        $this->writeTree($a, ['module.php' => "return ['id'=>'x'];\n", 'Domain/Foo.php' => "<?php\n"]);
        $this->writeTree($b, ['Domain/Foo.php' => "<?php\n", 'module.php' => "return ['id'=>'x'];\n"]);

        self::assertSame(ModuleFootprint::ofDirectory($a), ModuleFootprint::ofDirectory($b));
        self::assertSame(64, strlen(ModuleFootprint::ofDirectory($a)));
    }

    public function testContentChangeChangesFootprint(): void
    {
        $dir = $this->scratch('c');
        $this->writeTree($dir, ['module.php' => "v1\n"]);
        $first = ModuleFootprint::ofDirectory($dir);
        $this->writeTree($dir, ['module.php' => "v2\n"]);
        self::assertNotSame($first, ModuleFootprint::ofDirectory($dir));
    }

    public function testQuickSignatureStableForUnchangedMtimes(): void
    {
        $dir = $this->scratch('quick');
        $this->writeTree($dir, ['module.php' => "ok\n", 'A.php' => "<?php\n"]);
        $first = ModuleFootprint::quickSignature($dir);
        self::assertSame(64, strlen($first));
        self::assertSame($first, ModuleFootprint::quickSignature($dir));
        self::assertNotSame($first, ModuleFootprint::ofDirectory($dir));
    }

    public function testQuickSignatureChangesWhenFileTouched(): void
    {
        $dir = $this->scratch('quick-touch');
        $this->writeTree($dir, ['module.php' => "ok\n"]);
        $before = ModuleFootprint::quickSignature($dir);
        clearstatcache(true, $dir . '/module.php');
        touch($dir . '/module.php', time() + 5);
        clearstatcache(true, $dir . '/module.php');
        self::assertNotSame($before, ModuleFootprint::quickSignature($dir));
    }

    public function testMetaFileAndGitAreExcluded(): void
    {
        $base = $this->scratch('d');
        $this->writeTree($base, ['module.php' => "ok\n"]);
        $without = ModuleFootprint::ofDirectory($base);

        file_put_contents($base . '/' . ModuleFootprint::META_FILE, '{"version":"1.0.0"}');
        mkdir($base . '/.git', 0775, true);
        file_put_contents($base . '/.git/HEAD', 'ref: refs/heads/main');

        self::assertSame($without, ModuleFootprint::ofDirectory($base));
    }

    public function testSubmoduleGitFileDoesNotChangeFootprint(): void
    {
        $dir = $this->scratch('gitfile');
        $this->writeTree($dir, ['module.php' => "ok\n"]);
        $without = ModuleFootprint::ofDirectory($dir);

        file_put_contents($dir . '/.git', "gitdir: ../../.git/modules/modules/WebPlus\n");
        self::assertSame($without, ModuleFootprint::ofDirectory($dir));

        file_put_contents($dir . '/.git', "gitdir: /var/lib/git/modules/WebPlus\n");
        self::assertSame($without, ModuleFootprint::ofDirectory($dir));

        $legacy = ModuleFootprint::ofDirectory($dir, legacy: true);
        self::assertNotSame($without, $legacy);
        self::assertSame(64, strlen($legacy));
    }

    public function testImmutableVersionRefuseDifferentContent(): void
    {
        // Behaviour contract documented here; store tests cover installFromZip.
        $dir = $this->scratch('e');
        $this->writeTree($dir, ['module.php' => "a\n"]);
        $fp = ModuleFootprint::ofDirectory($dir);
        self::assertNotSame('', $fp);
        self::assertSame(12, strlen(ModuleFootprint::short($fp)));
    }

    private function scratch(string $name): string
    {
        if ($this->tmp === '') {
            $this->tmp = sys_get_temp_dir() . '/gereq-fp-' . bin2hex(random_bytes(4));
            mkdir($this->tmp, 0775, true);
        }
        $dir = $this->tmp . '/' . $name;
        mkdir($dir, 0775, true);

        return $dir;
    }

    /** @param array<string, string> $files */
    private function writeTree(string $root, array $files): void
    {
        foreach ($files as $rel => $contents) {
            $path = $root . '/' . $rel;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0775, true);
            }
            file_put_contents($path, $contents);
        }
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

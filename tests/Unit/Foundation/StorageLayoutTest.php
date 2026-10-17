<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Foundation;

use Gereq\Foundation\StorageLayout;
use PHPUnit\Framework\TestCase;

final class StorageLayoutTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $this->removeTree($this->tmp);
        }
        parent::tearDown();
    }

    public function testEnsureCreatesRuntimeStorageTree(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gereq-storage-' . uniqid('', true);
        self::assertTrue(mkdir($this->tmp, 0775, true));

        StorageLayout::ensure($this->tmp);

        foreach ([
            'storage/cache/data',
            'storage/cache/storefront',
            'storage/cache/twig',
            'storage/cache/doctrine',
            'storage/cache/rate',
            'storage/cache/media',
            'storage/logs',
            'storage/sessions',
            'storage/tmp/queue',
            'storage/uploads',
            'storage/framework',
        ] as $relative) {
            self::assertDirectoryExists($this->tmp . '/' . $relative);
        }
    }

    public function testEnsureCacheRecreatesPoolsAfterWipe(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gereq-storage-cache-' . uniqid('', true);
        self::assertTrue(mkdir($this->tmp . '/storage/cache/data', 0775, true));
        file_put_contents($this->tmp . '/storage/cache/data/stale', 'x');

        $this->removeTree($this->tmp . '/storage/cache');
        StorageLayout::ensureCache($this->tmp);

        foreach (StorageLayout::CACHE_SUBDIRS as $subdir) {
            self::assertDirectoryExists($this->tmp . '/storage/cache/' . $subdir);
        }
        self::assertFileDoesNotExist($this->tmp . '/storage/cache/data/stale');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cookie\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository;
use Gereq\File\FileManager;
use Gereq\Tests\Support\PassthroughTranslator;
use Modules\Cookie\Application\CookieImageStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CookieImageStoreTest extends TestCase
{
    private string $root;
    private CookieImageStore $images;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cookie-images-' . bin2hex(random_bytes(8));
        $this->images = new CookieImageStore(new FileManager(
            $this->createMock(EntityManagerInterface::class),
            new Repository(['files' => ['local_path' => 'uploads']]),
            $this->root,
            new PassthroughTranslator(),
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/uploads/cookie/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->root . '/uploads/cookie');
        @rmdir($this->root . '/uploads');
        @unlink($this->root . '/upload');
        @rmdir($this->root);
    }

    public function testUploadsAreImmutableAndOnlyOwnRasterFilesCanBeServed(): void
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        self::assertIsString($gif);
        file_put_contents($this->root . '/upload', $gif);
        $upload = new UploadedFile($this->root . '/upload', 'custom.gif', null, null, true);
        $first = $this->images->store($upload);
        $second = $this->images->store($upload);
        self::assertNotSame($first, $second);
        $path = $this->images->path($first);
        self::assertNotNull($path);
        self::assertSame($gif, file_get_contents($path));
        self::assertSame('image/gif', CookieImageStore::mime($first));
        self::assertNull($this->images->path('../secret.png'));
        self::assertNull($this->images->path('other-module.png'));
        self::assertNull($this->images->path(str_repeat('a', 32) . '.svg'));
    }

    public function testSvgDisguisedAsPngIsRejected(): void
    {
        file_put_contents($this->root . '/upload', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cookie.image.error');
        $this->images->store(new UploadedFile($this->root . '/upload', 'fake.png', 'image/png', null, true));
    }

    public function testOversizedImagesAreRejected(): void
    {
        file_put_contents($this->root . '/upload', str_repeat('x', 2 * 1024 * 1024 + 1));
        $this->expectException(\RuntimeException::class);
        $this->images->store(new UploadedFile($this->root . '/upload', 'large.png', null, null, true));
    }
}

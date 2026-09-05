<?php

declare(strict_types=1);

namespace Gereq\Tests\File;

use Gereq\File\ImageConverter;
use PHPUnit\Framework\TestCase;

final class ImageConverterTest extends TestCase
{
    public function testConvertsJpegToAvifOrWebpWhenGdAvailable(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required');
        }

        $converter = new ImageConverter();
        if (!$converter->supportsAvif() && !$converter->supportsWebp()) {
            $this->markTestSkipped('GD without AVIF/WebP');
        }

        $image = imagecreatetruecolor(32, 24);
        $this->assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, 31, 23, imagecolorallocate($image, 20, 80, 160));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $result = $converter->convert($jpeg, 'image/jpeg');
        $this->assertNotNull($result);
        $this->assertContains($result['mime'], ['image/avif', 'image/webp']);
        $this->assertContains($result['extension'], ['avif', 'webp']);
        $this->assertSame(32, $result['width']);
        $this->assertSame(24, $result['height']);
        $this->assertNotSame('', $result['contents']);

        if ($converter->supportsAvif()) {
            $this->assertSame('image/avif', $result['mime']);
        } else {
            $this->assertSame('image/webp', $result['mime']);
        }
    }

    public function testLeavesGifUntouched(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required');
        }

        $image = imagecreatetruecolor(8, 8);
        $this->assertNotFalse($image);
        ob_start();
        imagegif($image);
        $gif = (string) ob_get_clean();
        imagedestroy($image);

        $this->assertNull((new ImageConverter())->convert($gif, 'image/gif'));
    }
}

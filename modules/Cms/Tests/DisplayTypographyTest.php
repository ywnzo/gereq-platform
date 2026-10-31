<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Testing\TestCase;
use Modules\Cms\Application\BlockDesignNormalizer;

final class DisplayTypographyTest extends TestCase
{
    public function testNativeDisplaySizesSurviveNormalizationAndUnknownSizesAreRejected(): void
    {
        foreach (['display', 'display-sm', 'display-md', 'eyebrow'] as $size) {
            self::assertSame($size, BlockDesignNormalizer::merge([], ['font_size' => $size])['font_size']);
        }
        self::assertSame('inherit', BlockDesignNormalizer::merge([], ['font_size' => '88px;position:fixed'])['font_size']);
    }
}

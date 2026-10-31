<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Testing\TestCase;
use Modules\Cms\Application\BlockDesignNormalizer;
use Modules\Cms\Application\CmsSsgHtmlRenderer;

final class BlockSpacingTest extends TestCase
{
    public function testNormalizationPreservesZeroAndRemovesUnsetOverrides(): void
    {
        $raw = ['margin' => 300, 'margin_top' => 0, 'margin_left' => '', 'padding' => 12, 'padding_bottom' => -2, 'padding_right' => 300];
        $result = BlockDesignNormalizer::merge([], $raw);
        self::assertSame(256, $result['margin']);
        self::assertSame(0, $result['margin_top']);
        self::assertArrayNotHasKey('margin_left', $result);
        self::assertSame(12, $result['padding']);
        self::assertSame(0, $result['padding_bottom']);
        self::assertSame(300, $result['padding_right']);
        self::assertArrayNotHasKey('margin', BlockDesignNormalizer::merge([], []));
    }

    public function testSnapshotRendersGeneralBeforeSideOverrides(): void
    {
        $html = (new CmsSsgHtmlRenderer())->renderFragment([['type' => 'text', 'payload' => [
            'body' => '<p>Text</p>', 'margin' => 20, 'margin_top' => 0, 'padding' => 12, 'padding_left' => 4,
        ]]]);
        self::assertStringContainsString('margin:20px;margin-top:0px;padding:12px;padding-left:4px', $html);
    }
}

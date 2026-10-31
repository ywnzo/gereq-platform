<?php

declare(strict_types=1);

namespace Modules\WebPlus\Tests;

use Modules\WebPlus\Application\Health\CmsMediaPagespeed;
use Modules\WebPlus\Application\Health\FrontendHtmlPagespeed;
use Modules\WebPlus\Application\Health\MediaDeliveryPagespeed;
use PHPUnit\Framework\TestCase;

final class PagespeedHealthTest extends TestCase
{
    public function testFrontendHtmlDetectsSpaInjectAndLcpPreload(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html><head>
<title data-webplus="1">Domů</title>
<meta property="og:image" content="https://ex.test/a.avif" data-webplus="1">
<link rel="preload" as="image" href="https://ex.test/a.avif" data-gereq-lcp="1">
<meta name="gereq-webplus-spa" content="1" data-gereq-webplus-spa="1">
</head><body><div id="app"></div></body></html>
HTML;

        $facts = FrontendHtmlPagespeed::analyze($html);
        self::assertTrue($facts['has_spa_inject']);
        self::assertTrue($facts['has_lcp_preload']);
        self::assertTrue($facts['has_og_image']);
        self::assertTrue($facts['spa_shell']);
        self::assertFalse($facts['eager_rounded_sharp']);
    }

    public function testFrontendHtmlFlagsEagerRoundedSharpFonts(): void
    {
        $html = '<link rel="stylesheet" href="/assets/material-symbols-rounded-CFzFAzDo.woff2">';
        $facts = FrontendHtmlPagespeed::analyze($html);
        self::assertTrue($facts['eager_rounded_sharp']);
    }

    public function testCmsMediaSummarizeCountsSrcsetAndDimensions(): void
    {
        $summary = CmsMediaPagespeed::summarizeBlocks([
            [
                'type' => 'image',
                'payload' => [
                    'src' => '/media/1/file?w=960',
                    'srcset' => '/media/1/file?w=480 480w',
                    'width' => 1600,
                    'height' => 900,
                ],
            ],
            [
                'type' => 'gallery',
                'payload' => [
                    'images' => [
                        ['url' => '/media/2/file', 'width' => 800, 'height' => 600],
                        ['url' => '/media/3/file?w=640', 'width' => null, 'height' => null],
                    ],
                ],
            ],
            [
                'type' => 'layout',
                'payload' => [
                    'children' => [
                        [
                            'type' => 'image',
                            'payload' => ['url' => '/media/4/file'],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame(4, $summary['images']);
        self::assertSame(2, $summary['with_srcset']);
        self::assertSame(2, $summary['missing_srcset']);
        self::assertSame(2, $summary['missing_dimensions']);
    }

    public function testMediaCacheControlRequiresImmutablePublic(): void
    {
        self::assertTrue(MediaDeliveryPagespeed::cacheControlIsImmutablePublic(
            'public, max-age=604800, immutable, stale-while-revalidate=86400',
        ));
        self::assertFalse(MediaDeliveryPagespeed::cacheControlIsImmutablePublic('private, max-age=300'));
        self::assertFalse(MediaDeliveryPagespeed::cacheControlIsImmutablePublic(null));
    }

    public function testOutlinedFontBudgetScansStorefrontAssets(): void
    {
        $tmp = sys_get_temp_dir() . '/gereq-font-budget-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/assets', 0777, true);
        file_put_contents($tmp . '/assets/material-symbols-outlined-test.woff2', str_repeat('x', 250_000));

        try {
            $bytes = MediaDeliveryPagespeed::largestOutlinedFontBytes($tmp);
            self::assertSame(250_000, $bytes);
            self::assertGreaterThan(MediaDeliveryPagespeed::OUTLINED_FONT_SOFT_BYTES, $bytes);
        } finally {
            @unlink($tmp . '/assets/material-symbols-outlined-test.woff2');
            @rmdir($tmp . '/assets');
            @rmdir($tmp);
        }
    }
}

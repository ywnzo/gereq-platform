<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Feeds;

use Modules\Feeds\Application\Drivers\GoogleMerchantDriver;
use PHPUnit\Framework\TestCase;

final class GoogleMerchantDriverTest extends TestCase
{
    public function testRendersRssWithEscapedFields(): void
    {
        $driver = new GoogleMerchantDriver();
        $xml = $driver->render(
            ['title' => 'Shop & Co', 'link' => 'https://example.com', 'description' => 'Feed'],
            [[
                'id' => 'sku-1',
                'title' => 'Kolo <pro> děti',
                'description' => 'Popis & více',
                'link' => 'https://example.com/p/1',
                'image_link' => 'https://example.com/i.jpg',
                'price' => '1990.00 CZK',
                'availability' => 'in_stock',
                'brand' => 'GEREQ',
            ]],
        );

        self::assertSame('google_merchant', $driver->key());
        self::assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $xml);
        self::assertStringContainsString('<title>Shop &amp; Co</title>', $xml);
        self::assertStringContainsString('<g:title>Kolo &lt;pro&gt; děti</g:title>', $xml);
        self::assertStringContainsString('<g:description>Popis &amp; více</g:description>', $xml);
        self::assertStringContainsString('<g:price>1990.00 CZK</g:price>', $xml);
        self::assertStringContainsString('<g:brand>GEREQ</g:brand>', $xml);
    }
}

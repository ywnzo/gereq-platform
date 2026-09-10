<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\WebPlus;

use Modules\WebPlus\Application\UrlTemplateDiscoverer;
use PHPUnit\Framework\TestCase;

final class UrlTemplateDiscovererTest extends TestCase
{
    public function testDiscoversListingStylePrefixTemplate(): void
    {
        $rows = (new UrlTemplateDiscoverer())->discover([
            '/nabidky/chalupa-rymarov',
            '/nabidky/byt-2kk-pribor',
            '/nabidky/dum-krnov',
            '/nabidky',
            '/',
        ]);

        $templates = array_column($rows, 'template');
        self::assertContains('/nabidky/{slug}', $templates);
        $hit = null;
        foreach ($rows as $row) {
            if ($row['template'] === '/nabidky/{slug}') {
                $hit = $row;
                break;
            }
        }
        self::assertNotNull($hit);
        self::assertSame(3, $hit['hit_count']);
        self::assertSame(['slug'], $hit['tokens']);
    }

    public function testDiscoversDepthThreeTemplate(): void
    {
        $rows = (new UrlTemplateDiscoverer())->discover([
            '/blog/tipy/2024',
            '/blog/tipy/2025',
            '/blog/novinky/2024',
        ]);

        $templates = array_column($rows, 'template');
        self::assertTrue(
            in_array('/blog/{x}/{y}', $templates, true)
            || in_array('/blog/{slug}', $templates, true)
            || in_array('/blog/tipy/{slug}', $templates, true),
        );
    }

    public function testIgnoresExistingTemplatesAndNeedsMinHits(): void
    {
        $rows = (new UrlTemplateDiscoverer())->discover([
            '/nabidky/{slug}',
            '/only-one/detail',
        ]);
        self::assertSame([], $rows);
    }

    public function testSingleSegmentClusterBecomesPageToken(): void
    {
        $rows = (new UrlTemplateDiscoverer())->discover([
            '/o-nas',
            '/kontakt',
            '/cookies',
        ]);

        $templates = array_column($rows, 'template');
        self::assertContains('/{page}', $templates);
    }
}

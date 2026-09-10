<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\WebPlus;

use Modules\WebPlus\Application\PathPattern;
use PHPUnit\Framework\TestCase;

final class PathPatternTest extends TestCase
{
    public function testExactPathIsNotATemplate(): void
    {
        self::assertFalse(PathPattern::isTemplate('/nabidky'));
        self::assertTrue(PathPattern::isTemplate('/nabidky/{slug}'));
    }

    public function testMatchListingSlug(): void
    {
        $params = PathPattern::match('/nabidky/{slug}', '/nabidky/chalupa-rymarov');
        self::assertSame(['slug' => 'chalupa-rymarov'], $params);
    }

    public function testListIndexDoesNotMatchSlugTemplate(): void
    {
        self::assertNull(PathPattern::match('/nabidky/{slug}', '/nabidky'));
    }

    public function testGenericThreeSegmentPath(): void
    {
        $params = PathPattern::match('/{page}/{x}/{y}', '/blog/tipy/2026');
        self::assertSame(['page' => 'blog', 'x' => 'tipy', 'y' => '2026'], $params);
    }

    public function testSpecificityPrefersStaticSegments(): void
    {
        self::assertGreaterThan(
            PathPattern::specificity('/{page}/{x}'),
            PathPattern::specificity('/nabidky/{slug}'),
        );
    }

    public function testInterpolateTokens(): void
    {
        self::assertSame(
            'Chalupa Rýmařov · Rýmařov',
            PathPattern::interpolate('{title} · {city}', [
                'title' => 'Chalupa Rýmařov',
                'city' => 'Rýmařov',
            ]),
        );
    }

    public function testSegmentVarsExposePageXYAndSlug(): void
    {
        $vars = PathPattern::segmentVars('/nabidky/chalupa-rymarov');
        self::assertSame('nabidky', $vars['page']);
        self::assertSame('chalupa-rymarov', $vars['x']);
        self::assertSame('chalupa-rymarov', $vars['slug']);
    }
}

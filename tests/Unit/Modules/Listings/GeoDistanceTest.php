<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Listings;

use Modules\Listings\Application\GeoDistance;
use PHPUnit\Framework\TestCase;

final class GeoDistanceTest extends TestCase
{
    public function testBetweenReturnsNullForMissingOrInvalidCoords(): void
    {
        self::assertNull(GeoDistance::between(null, 14.4, 50.0, 14.5));
        self::assertNull(GeoDistance::between(50.0, 14.4, 91.0, 14.5));
        self::assertNull(GeoDistance::between(50.0, 181.0, 50.1, 14.5));
    }

    public function testSamePointIsZeroDistance(): void
    {
        $result = GeoDistance::between(50.087, 14.421, 50.087, 14.421);
        self::assertNotNull($result);
        self::assertSame(0, $result['distance_m']);
        self::assertSame(0, $result['walk_minutes']);
    }

    public function testPragueDistanceIsReasonable(): void
    {
        // Václavské nám. → Karlův most ~1.5–2 km
        $meters = GeoDistance::meters(50.081, 14.427, 50.086, 14.411);
        self::assertGreaterThan(1000, $meters);
        self::assertLessThan(2500, $meters);
        self::assertSame((int) round($meters / 80), GeoDistance::walkMinutes($meters));
    }

    public function testWalkMinutesMinimumOneForPositiveDistance(): void
    {
        self::assertSame(1, GeoDistance::walkMinutes(1));
        self::assertSame(0, GeoDistance::walkMinutes(0));
    }
}

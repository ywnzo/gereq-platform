<?php

declare(strict_types=1);

namespace Modules\Listings\Application;

/**
 * Crow-flies distance (Haversine) + rough walk-time estimate.
 * Walk speed defaults to ~4.8 km/h (80 m/min), typical urban pace.
 */
final class GeoDistance
{
    private const EARTH_RADIUS_M = 6_371_000.0;
    private const WALK_METERS_PER_MINUTE = 80.0;

    /**
     * @return array{distance_m: int, walk_minutes: int}|null
     */
    public static function between(
        ?float $lat1,
        ?float $lon1,
        ?float $lat2,
        ?float $lon2,
    ): ?array {
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            return null;
        }
        if (!self::validCoord($lat1, $lon1) || !self::validCoord($lat2, $lon2)) {
            return null;
        }

        $meters = self::meters($lat1, $lon1, $lat2, $lon2);

        return [
            'distance_m' => $meters,
            'walk_minutes' => self::walkMinutes($meters),
        ];
    }

    public static function meters(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lon2 - $lon1);

        $a = sin($Δφ / 2) ** 2
            + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return (int) max(0, (int) round(self::EARTH_RADIUS_M * $c));
    }

    public static function walkMinutes(int $meters): int
    {
        if ($meters <= 0) {
            return 0;
        }

        return max(1, (int) round($meters / self::WALK_METERS_PER_MINUTE));
    }

    private static function validCoord(float $lat, float $lon): bool
    {
        return $lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0;
    }
}

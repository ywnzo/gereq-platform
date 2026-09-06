<?php

declare(strict_types=1);

namespace Modules\BikeService\Application;

/**
 * OpenStreetMap Overpass — blízké cykloservisy, pumpy a nabíjení e-kol.
 */
final class OsmBikePoisClient
{
    private const ENDPOINT = 'https://overpass-api.de/api/interpreter';

    /**
     * @return array{ok: bool, code: int, places: list<array<string, mixed>>, error: ?string}
     */
    public function nearby(float $lat, float $lon, int $radiusMeters = 2500, int $limit = 20): array
    {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return ['ok' => false, 'code' => 0, 'places' => [], 'error' => 'bikeservice.error.coords_invalid'];
        }
        $radiusMeters = max(200, min(15000, $radiusMeters));
        $limit = max(1, min(40, $limit));

        $query = sprintf(
            '[out:json][timeout:20];('
            . 'node["shop"="bicycle"](around:%d,%F,%F);'
            . 'way["shop"="bicycle"](around:%d,%F,%F);'
            . 'node["amenity"="bicycle_repair_station"](around:%d,%F,%F);'
            . 'node["amenity"="charging_station"]["bicycle"="yes"](around:%d,%F,%F);'
            . 'node["service:bicycle:repair"="yes"](around:%d,%F,%F);'
            . ');out center tags %d;',
            $radiusMeters,
            $lat,
            $lon,
            $radiusMeters,
            $lat,
            $lon,
            $radiusMeters,
            $lat,
            $lon,
            $radiusMeters,
            $lat,
            $lon,
            $radiusMeters,
            $lat,
            $lon,
            $limit,
        );

        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) {
            return ['ok' => false, 'code' => 0, 'places' => [], 'error' => 'bikeservice.error.api_request_failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: GEREQ-BikeService/1.0 (workshop admin; contact via app)',
            ],
            CURLOPT_TIMEOUT => 22,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'code' => $code, 'places' => [], 'error' => $curlError !== '' ? $curlError : 'bikeservice.error.api_request_failed'];
        }
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'code' => $code, 'places' => [], 'error' => 'bikeservice.error.api_invalid'];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'code' => $code, 'places' => [], 'error' => 'HTTP ' . $code];
        }

        $elements = $decoded['elements'] ?? [];
        if (!is_array($elements)) {
            return ['ok' => true, 'code' => $code, 'places' => [], 'error' => null];
        }

        $places = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];
            $plat = $el['lat'] ?? ($el['center']['lat'] ?? null);
            $plon = $el['lon'] ?? ($el['center']['lon'] ?? null);
            if (!is_numeric($plat) || !is_numeric($plon)) {
                continue;
            }
            $kind = 'shop';
            if (($tags['amenity'] ?? '') === 'bicycle_repair_station') {
                $kind = 'repair_station';
            } elseif (($tags['amenity'] ?? '') === 'charging_station') {
                $kind = 'charging';
            } elseif (($tags['service:bicycle:repair'] ?? '') === 'yes' && ($tags['shop'] ?? '') !== 'bicycle') {
                $kind = 'repair';
            }
            $places[] = [
                'id' => (string) ($el['id'] ?? ''),
                'kind' => $kind,
                'name' => (string) ($tags['name'] ?? $tags['brand'] ?? '—'),
                'lat' => (float) $plat,
                'lon' => (float) $plon,
                'opening_hours' => isset($tags['opening_hours']) ? (string) $tags['opening_hours'] : null,
                'website' => isset($tags['website']) ? (string) $tags['website'] : (isset($tags['url']) ? (string) $tags['url'] : null),
                'phone' => isset($tags['phone']) ? (string) $tags['phone'] : null,
                'ebike' => ($tags['service:bicycle:ebike'] ?? '') === 'yes',
                'distance_m' => (int) round($this->haversine($lat, $lon, (float) $plat, (float) $plon)),
            ];
        }

        usort($places, static fn (array $a, array $b): int => ($a['distance_m'] <=> $b['distance_m']));

        return ['ok' => true, 'code' => $code, 'places' => array_slice($places, 0, $limit), 'error' => null];
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lon2 - $lon1);
        $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;

        return 2 * $r * atan2(sqrt($a), sqrt(1 - $a));
    }
}

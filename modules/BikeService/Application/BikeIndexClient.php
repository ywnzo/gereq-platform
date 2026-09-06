<?php

declare(strict_types=1);

namespace Modules\BikeService\Application;

/**
 * Veřejné Bike Index API v3 — hledání kol podle sériového čísla / výrobce.
 * @see https://bikeindex.org/documentation/api_v3
 */
final class BikeIndexClient
{
    private const BASE = 'https://bikeindex.org/api/v3';

    /**
     * @return array{ok: bool, code: int, bikes: list<array<string, mixed>>, error: ?string}
     */
    public function searchBySerial(string $serial, int $perPage = 10): array
    {
        $serial = trim($serial);
        if ($serial === '') {
            return ['ok' => false, 'code' => 0, 'bikes' => [], 'error' => 'bikeservice.error.serial_required'];
        }

        return $this->get('/search', [
            'serial' => $serial,
            'per_page' => max(1, min(25, $perPage)),
            'stolenness' => 'all',
        ], 'bikes');
    }

    /**
     * @return array{ok: bool, code: int, bikes: list<array<string, mixed>>, error: ?string}
     */
    public function searchStolenNearby(string $location, int $distanceMiles = 50, int $perPage = 10): array
    {
        $location = trim($location);
        if ($location === '') {
            return ['ok' => false, 'code' => 0, 'bikes' => [], 'error' => 'bikeservice.error.location_required'];
        }

        return $this->get('/search', [
            'location' => $location,
            'distance' => max(1, min(200, $distanceMiles)),
            'stolenness' => 'proximity',
            'per_page' => max(1, min(25, $perPage)),
        ], 'bikes');
    }

    /**
     * @return array{ok: bool, code: int, manufacturers: list<array<string, mixed>>, error: ?string}
     */
    public function manufacturers(string $query = '', int $perPage = 25): array
    {
        $query = trim($query);
        $params = ['per_page' => max(1, min(100, $query !== '' ? 100 : $perPage))];
        $result = $this->get('/manufacturers', $params, 'manufacturers');
        if (!$result['ok'] || $query === '') {
            return [
                'ok' => $result['ok'],
                'code' => $result['code'],
                'manufacturers' => array_slice($result['items'], 0, $perPage),
                'error' => $result['error'],
            ];
        }
        $q = mb_strtolower($query);
        $filtered = array_values(array_filter(
            $result['items'],
            static function (array $m) use ($q): bool {
                $name = mb_strtolower((string) ($m['name'] ?? ''));
                $short = mb_strtolower((string) ($m['short_name'] ?? ''));

                return str_contains($name, $q) || str_contains($short, $q);
            },
        ));

        return [
            'ok' => true,
            'code' => $result['code'],
            'manufacturers' => array_slice($filtered, 0, $perPage),
            'error' => null,
        ];
    }

    /**
     * Jedna stránka výrobců (pro sync katalogu).
     *
     * @return array{ok: bool, code: int, manufacturers: list<array<string, mixed>>, error: ?string}
     */
    public function manufacturersPage(int $page = 1, int $perPage = 100): array
    {
        $result = $this->get('/manufacturers', [
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
        ], 'manufacturers');

        return [
            'ok' => $result['ok'],
            'code' => $result['code'],
            'manufacturers' => $result['ok'] ? $result['items'] : [],
            'error' => $result['error'],
        ];
    }

    /**
     * @return array{ok: bool, code: int, manufacturer: ?array<string, mixed>, error: ?string}
     */
    public function manufacturer(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'code' => 0, 'manufacturer' => null, 'error' => 'bikeservice.error.api_empty'];
        }
        $raw = $this->request('GET', '/manufacturers/' . $id);
        if (!$raw['ok']) {
            return ['ok' => false, 'code' => $raw['code'], 'manufacturer' => null, 'error' => $raw['error']];
        }
        $manufacturer = $raw['data']['manufacturer'] ?? null;

        return [
            'ok' => is_array($manufacturer),
            'code' => $raw['code'],
            'manufacturer' => is_array($manufacturer) ? $manufacturer : null,
            'error' => is_array($manufacturer) ? null : 'bikeservice.error.api_empty',
        ];
    }

    /**
     * @return array{ok: bool, code: int, bike: ?array<string, mixed>, error: ?string}
     */
    public function find(int $id): array
    {
        $raw = $this->request('GET', '/bikes/' . $id);
        if (!$raw['ok']) {
            return ['ok' => false, 'code' => $raw['code'], 'bike' => null, 'error' => $raw['error']];
        }
        $bike = $raw['data']['bike'] ?? null;

        return [
            'ok' => is_array($bike),
            'code' => $raw['code'],
            'bike' => is_array($bike) ? $bike : null,
            'error' => is_array($bike) ? null : 'bikeservice.error.api_empty',
        ];
    }

    /**
     * @param array<string, scalar> $query
     * @return array{ok: bool, code: int, bikes?: list<array<string, mixed>>, items: list<array<string, mixed>>, error: ?string}
     */
    private function get(string $path, array $query, string $listKey): array
    {
        $raw = $this->request('GET', $path, $query);
        if (!$raw['ok']) {
            return ['ok' => false, 'code' => $raw['code'], 'bikes' => [], 'items' => [], 'error' => $raw['error']];
        }
        $list = $raw['data'][$listKey] ?? [];
        if (!is_array($list)) {
            return ['ok' => false, 'code' => $raw['code'], 'bikes' => [], 'items' => [], 'error' => 'bikeservice.error.api_invalid'];
        }
        /** @var list<array<string, mixed>> $normalized */
        $normalized = array_values(array_filter($list, 'is_array'));

        return [
            'ok' => true,
            'code' => $raw['code'],
            'bikes' => $normalized,
            'items' => $normalized,
            'error' => null,
        ];
    }

    /**
     * @param array<string, scalar> $query
     * @return array{ok: bool, code: int, data: array<string, mixed>, error: ?string}
     */
    private function request(string $method, string $path, array $query = []): array
    {
        $url = self::BASE . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'code' => 0, 'data' => [], 'error' => 'bikeservice.error.api_request_failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: GEREQ-BikeService/1.0',
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'code' => $code, 'data' => [], 'error' => $curlError !== '' ? $curlError : 'bikeservice.error.api_request_failed'];
        }
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'code' => $code, 'data' => [], 'error' => 'bikeservice.error.api_invalid'];
        }
        if ($code < 200 || $code >= 300) {
            $err = $decoded['error'] ?? $decoded['message'] ?? ('HTTP ' . $code);

            return ['ok' => false, 'code' => $code, 'data' => $decoded, 'error' => is_string($err) ? $err : 'bikeservice.error.api_request_failed'];
        }

        return ['ok' => true, 'code' => $code, 'data' => $decoded, 'error' => null];
    }
}

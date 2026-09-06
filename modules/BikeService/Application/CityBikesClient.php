<?php

declare(strict_types=1);

namespace Modules\BikeService\Application;

/**
 * CityBikes API — sítě sdílených kol / elektrokol.
 * @see https://api.citybik.es/v2/
 */
final class CityBikesClient
{
    private const BASE = 'https://api.citybik.es/v2';

    /**
     * @return array{ok: bool, code: int, networks: list<array<string, mixed>>, error: ?string}
     */
    public function networks(?string $country = null, ?string $cityQuery = null): array
    {
        $raw = $this->get('/networks', ['fields' => 'id,name,location,company']);
        if (!$raw['ok']) {
            return ['ok' => false, 'code' => $raw['code'], 'networks' => [], 'error' => $raw['error']];
        }
        $list = $raw['data']['networks'] ?? [];
        if (!is_array($list)) {
            return ['ok' => false, 'code' => $raw['code'], 'networks' => [], 'error' => 'bikeservice.error.api_invalid'];
        }
        /** @var list<array<string, mixed>> $networks */
        $networks = array_values(array_filter($list, 'is_array'));
        $country = $country !== null ? strtoupper(trim($country)) : '';
        $cityQuery = $cityQuery !== null ? mb_strtolower(trim($cityQuery)) : '';
        if ($country !== '' || $cityQuery !== '') {
            $networks = array_values(array_filter(
                $networks,
                static function (array $n) use ($country, $cityQuery): bool {
                    $loc = is_array($n['location'] ?? null) ? $n['location'] : [];
                    $c = strtoupper((string) ($loc['country'] ?? ''));
                    $city = mb_strtolower((string) ($loc['city'] ?? ''));
                    if ($country !== '' && $c !== $country) {
                        return false;
                    }
                    if ($cityQuery !== '' && !str_contains($city, $cityQuery) && !str_contains(mb_strtolower((string) ($n['name'] ?? '')), $cityQuery)) {
                        return false;
                    }

                    return true;
                },
            ));
        }

        return ['ok' => true, 'code' => $raw['code'], 'networks' => $networks, 'error' => null];
    }

    /**
     * @return array{ok: bool, code: int, network: ?array<string, mixed>, error: ?string}
     */
    public function network(string $id): array
    {
        $id = trim($id);
        if ($id === '' || !preg_match('/^[a-z0-9\-]+$/i', $id)) {
            return ['ok' => false, 'code' => 0, 'network' => null, 'error' => 'bikeservice.error.network_required'];
        }
        $raw = $this->get('/networks/' . rawurlencode($id), ['fields' => 'id,name,location,company,stations']);
        if (!$raw['ok']) {
            return ['ok' => false, 'code' => $raw['code'], 'network' => null, 'error' => $raw['error']];
        }
        $network = $raw['data']['network'] ?? null;

        return [
            'ok' => is_array($network),
            'code' => $raw['code'],
            'network' => is_array($network) ? $network : null,
            'error' => is_array($network) ? null : 'bikeservice.error.api_empty',
        ];
    }

    /**
     * @param array<string, scalar> $query
     * @return array{ok: bool, code: int, data: array<string, mixed>, error: ?string}
     */
    private function get(string $path, array $query = []): array
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
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: GEREQ-BikeService/1.0',
            ],
            CURLOPT_TIMEOUT => 12,
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
            return ['ok' => false, 'code' => $code, 'data' => $decoded, 'error' => 'HTTP ' . $code];
        }

        return ['ok' => true, 'code' => $code, 'data' => $decoded, 'error' => null];
    }
}

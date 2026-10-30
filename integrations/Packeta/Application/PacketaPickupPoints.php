<?php

declare(strict_types=1);

namespace Integrations\Packeta\Application;

use Gereq\Cache\AppCache;
use Gereq\Search\TextNormalizer;
use Gereq\Setting\Settings;
use Modules\Shop\Domain\PickupPoint;

/** Uses the official country-filtered branch and box feeds, cached for six hours. */
final class PacketaPickupPoints
{
    public function __construct(private readonly Settings $settings, private readonly AppCache $cache)
    {
    }

    public static function cacheKey(string $apiKey, string $country, string $kind): string
    {
        return 'packeta.pickup.v1.' . hash('sha256', $apiKey . ':' . strtoupper($country) . ':' . $kind);
    }

    /** @return list<PickupPoint> */
    public function search(string $country, string $query, int $limit = 20): array
    {
        $fold = new TextNormalizer();
        $tokens = $fold->tokens($query);
        $matches = [];
        foreach ($this->points($country) as $point) {
            $text = $fold->fold(implode(' ', [$point->name, $point->street, $point->city, str_replace(' ', '', $point->zip), $point->zip, $point->id]));
            foreach ($tokens as $token) {
                if (!str_contains($text, $token)) {
                    continue 2;
                }
            }
            $matches[] = $point;
        }
        usort($matches, static fn (PickupPoint $a, PickupPoint $b): int => strcmp($a->city . $a->name, $b->city . $b->name));

        return array_slice($matches, 0, max(1, min(50, $limit)));
    }

    public function resolve(string $country, string $id): PickupPoint
    {
        if (!preg_match('/^[0-9]{1,64}$/D', $id)) {
            throw new \InvalidArgumentException('shop.error.pickup_invalid');
        }
        foreach ($this->points($country) as $point) {
            if ($point->id === $id) {
                return $point;
            }
        }

        throw new \InvalidArgumentException('shop.error.pickup_invalid');
    }

    /** @return list<PickupPoint> */
    private function points(string $country): array
    {
        $country = strtoupper(trim($country));
        $key = trim((string) $this->settings->get('shop.packeta_api_key', ''));
        if ($key === '' || !preg_match('/^[A-Z]{2}$/D', $country)) {
            throw new \RuntimeException('shop.error.pickup_unavailable');
        }
        $result = [];
        foreach (['branch', 'box'] as $kind) {
            $rows = $this->cache->remember(self::cacheKey($key, $country, $kind), fn (): array => $this->download($key, $country, $kind), 21600);
            foreach ($rows as $row) {
                $point = is_array($row) ? self::normalize($row, $country, $kind) : null;
                if ($point !== null) {
                    $result[$point->id] = $point;
                }
            }
        }

        return array_values($result);
    }

    /** @param array<string, mixed> $row */
    public static function normalize(array $row, string $country, string $kind, ?string $today = null): ?PickupPoint
    {
        $today ??= gmdate('Y-m-d');
        $id = $row['id'] ?? null;
        if ((!is_string($id) && !is_int($id)) || !preg_match('/^[0-9]{1,64}$/D', (string) $id)
            || !is_string($row['country'] ?? null) || strtoupper($row['country']) !== $country
            || !in_array($row['displayFrontend'] ?? null, ['1', 1, true], true)
            || !is_array($row['status'] ?? null) || !in_array($row['status']['statusId'] ?? null, ['1', 1], true)
        ) {
            return null;
        }
        foreach (['name', 'street', 'city', 'zip'] as $field) {
            if (!isset($row[$field]) || !is_string($row[$field]) || trim($row[$field]) === '') {
                return null;
            }
        }
        foreach (['enterableUntil', 'openUntil', 'openSince'] as $field) {
            $date = $row[$field] ?? '';
            if ($date !== '' && (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date))) {
                return null;
            }
            if ($date !== '' && ($field === 'openSince' ? $date > $today : $date < $today)) {
                return null;
            }
        }
        if (is_string($row['holidayStart'] ?? null) && is_string($row['holidayEnd'] ?? null)
            && $row['holidayStart'] <= $today && $row['holidayEnd'] >= $today
        ) {
            return null;
        }

        return new PickupPoint(
            (string) $id,
            'packeta',
            $country,
            mb_substr(trim(strip_tags($row['name'])), 0, 190),
            trim(strip_tags($row['street'])),
            trim(strip_tags($row['city'])),
            trim($row['zip']),
            $kind === 'box' ? 'box' : 'branch',
            $kind !== 'box' || in_array($row['codAllowed'] ?? null, ['1', 1, true], true)
        );
    }

    /** @return list<array<string, mixed>> */
    private function download(string $key, string $country, string $kind): array
    {
        $url = 'https://pickup-point.api.packeta.com/v5/' . rawurlencode($key) . '/' . $kind . '/json?country=' . strtolower($country) . '&lang=en';
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('shop.error.pickup_unavailable');
        }
        $body = '';
        curl_setopt_array($handle, [CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 16777216) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            }]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $status !== 200) {
            // Never expose the credential-bearing feed URL or provider response to clients.
            throw new \RuntimeException('shop.error.pickup_unavailable');
        }
        try {
            $rows = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('shop.error.pickup_unavailable');
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException('shop.error.pickup_unavailable');
        }

        return $rows;
    }
}

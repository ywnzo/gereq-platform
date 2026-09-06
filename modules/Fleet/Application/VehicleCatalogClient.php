<?php

declare(strict_types=1);

namespace Modules\Fleet\Application;

/**
 * Open katalog značek/modelů (vehiclesdb CC-BY) + loga (car-logos-dataset).
 * Motorizace v free katalogu není — ta se bere z ORV u konkrétního vozidla.
 */
final class VehicleCatalogClient
{
    private const CATALOG_URL = 'https://cdn.jsdelivr.net/gh/vehiclesdb/vehiclesdb@main/dist/vehicles.min.json';
    private const LOGO_CDN = 'https://raw.githubusercontent.com/filippofilip95/car-logos-dataset/master/logos/thumb';
    private const CACHE_TTL = 604800; // 7 dní

    /** @var array{v?: string, makes?: list<mixed>, models?: list<mixed>}|null */
    private ?array $catalog = null;

    public function __construct(private readonly string $basePath) {}

    public function logoUrl(string $brandName): ?string
    {
        $slug = $this->logoSlug($brandName);
        if ($slug === null) {
            return null;
        }

        return self::LOGO_CDN . '/' . $slug . '.png';
    }

    /**
     * @return list<array{slug: string, name: string, kinds: list<string>, logo: ?string, model_count: int}>
     */
    public function searchMakes(string $query, int $limit = 20): array
    {
        $catalog = $this->load();
        $q = $this->normalize($query);
        $ranked = [];
        foreach ($catalog['makes'] ?? [] as $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }
            $slug = (string) $row[0];
            $name = (string) $row[1];
            $kinds = is_array($row[2] ?? null) ? array_values(array_map('strval', $row[2])) : [];
            if ($kinds !== [] && !in_array('car', $kinds, true) && !in_array('van', $kinds, true)) {
                continue;
            }
            $slugN = $this->normalize($slug);
            $nameN = $this->normalize($name);
            if ($q !== '' && !str_contains($slugN, $q) && !str_contains($nameN, $q)) {
                continue;
            }
            $score = 3;
            if ($q === '' || $slugN === $q || $nameN === $q) {
                $score = 0;
            } elseif (str_starts_with($slugN, $q) || str_starts_with($nameN, $q)) {
                $score = 1;
            } elseif (str_contains(' ' . $nameN . ' ', ' ' . $q . ' ')) {
                $score = 2;
            }
            $ranked[] = [
                'score' => $score,
                'item' => [
                    'slug' => $slug,
                    'name' => $name,
                    'kinds' => $kinds,
                    'logo' => $this->logoUrl($name),
                    'model_count' => $this->modelCountForSlug($slug),
                ],
            ];
        }
        usort($ranked, static function (array $a, array $b): int {
            return ($a['score'] <=> $b['score']) ?: strcasecmp($a['item']['name'], $b['item']['name']);
        });

        return array_map(static fn (array $row): array => $row['item'], array_slice($ranked, 0, $limit));
    }

    /**
     * @return list<array{slug: string, name: string, kind: string, body: ?string}>
     */
    public function modelsForBrand(string $brandNameOrSlug): array
    {
        $catalog = $this->load();
        $slug = $this->resolveMakeSlug($brandNameOrSlug);
        if ($slug === null) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($catalog['models'] ?? [] as $row) {
            if (!is_array($row) || count($row) < 3) {
                continue;
            }
            if ((string) $row[0] !== $slug) {
                continue;
            }
            $name = trim((string) $row[2]);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $kind = (string) ($row[3] ?? 'car');
            if (!in_array($kind, ['car', 'van'], true)) {
                continue;
            }
            $out[] = [
                'slug' => (string) $row[1],
                'name' => $name,
                'kind' => $kind,
                'body' => isset($row[4]) ? (string) $row[4] : null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    public function resolveCanonicalName(string $brandNameOrSlug): ?string
    {
        $slug = $this->resolveMakeSlug($brandNameOrSlug);
        if ($slug === null) {
            return null;
        }
        foreach ($this->load()['makes'] ?? [] as $row) {
            if (is_array($row) && ($row[0] ?? null) === $slug) {
                return (string) $row[1];
            }
        }

        return null;
    }

    /** @return array{v?: string, makes?: list<mixed>, models?: list<mixed>} */
    private function load(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        $path = $this->cachePath();
        if (is_file($path) && (time() - (int) filemtime($path)) < self::CACHE_TTL) {
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                return $this->catalog = $decoded;
            }
        }

        $ch = curl_init(self::CATALOG_URL);
        if ($ch === false) {
            return $this->catalog = $this->readStaleOrEmpty($path);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: GEREQ-Fleet/1.0'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $code !== 200) {
            return $this->catalog = $this->readStaleOrEmpty($path);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['makes'], $decoded['models'])) {
            return $this->catalog = $this->readStaleOrEmpty($path);
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $raw);

        return $this->catalog = $decoded;
    }

    /** @return array{v?: string, makes?: list<mixed>, models?: list<mixed>} */
    private function readStaleOrEmpty(string $path): array
    {
        if (is_file($path)) {
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['makes' => [], 'models' => []];
    }

    private function cachePath(): string
    {
        return rtrim($this->basePath, '/') . '/storage/cache/fleet_vehiclesdb.json';
    }

    private function modelCountForSlug(string $slug): int
    {
        $n = 0;
        foreach ($this->load()['models'] ?? [] as $row) {
            if (is_array($row) && ($row[0] ?? null) === $slug) {
                $kind = (string) ($row[3] ?? 'car');
                if (in_array($kind, ['car', 'van'], true)) {
                    ++$n;
                }
            }
        }

        return $n;
    }

    private function resolveMakeSlug(string $brandNameOrSlug): ?string
    {
        $needle = $this->normalize($brandNameOrSlug);
        if ($needle === '') {
            return null;
        }
        foreach ($this->load()['makes'] ?? [] as $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }
            $slug = (string) $row[0];
            $name = (string) $row[1];
            if ($this->normalize($slug) === $needle || $this->normalize($name) === $needle) {
                return $slug;
            }
        }
        // fuzzy: slug start / name contains
        foreach ($this->load()['makes'] ?? [] as $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }
            $slug = (string) $row[0];
            $name = (string) $row[1];
            if (str_starts_with($this->normalize($name), $needle) || str_starts_with($this->normalize($slug), $needle)) {
                return $slug;
            }
        }

        return null;
    }

    private function logoSlug(string $brandName): ?string
    {
        $aliases = [
            'skoda' => 'skoda',
            'škoda' => 'skoda',
            'volkswagen' => 'volkswagen',
            'vw' => 'volkswagen',
            'mercedes' => 'mercedes-benz',
            'mercedes benz' => 'mercedes-benz',
            'mercedes-benz' => 'mercedes-benz',
            'bmw' => 'bmw',
            'audi' => 'audi',
            'seat' => 'seat',
            'cupra' => 'cupra',
            'toyota' => 'toyota',
            'ford' => 'ford',
            'opel' => 'opel',
            'peugeot' => 'peugeot',
            'renault' => 'renault',
            'citroen' => 'citroen',
            'citroën' => 'citroen',
            'hyundai' => 'hyundai',
            'kia' => 'kia',
            'mazda' => 'mazda',
            'honda' => 'honda',
            'nissan' => 'nissan',
            'volvo' => 'volvo',
            'fiat' => 'fiat',
            'dacia' => 'dacia',
            'suzuki' => 'suzuki',
            'subaru' => 'subaru',
            'porsche' => 'porsche',
            'jaguar' => 'jaguar',
            'land rover' => 'land-rover',
            'landrover' => 'land-rover',
            'mini' => 'mini',
            'tesla' => 'tesla',
            'chevrolet' => 'chevrolet',
            'jeep' => 'jeep',
            'alfa romeo' => 'alfa-romeo',
            'alfaromeo' => 'alfa-romeo',
        ];
        $n = $this->normalize($brandName);
        if (isset($aliases[$n])) {
            return $aliases[$n];
        }
        $slug = $this->resolveMakeSlug($brandName);
        if ($slug !== null) {
            return $slug;
        }
        $fallback = preg_replace('/[^a-z0-9]+/', '-', $n) ?? '';
        $fallback = trim($fallback, '-');

        return $fallback !== '' ? $fallback : null;
    }

    private function normalize(string $value): string
    {
        $v = mb_strtolower(trim($value));
        $map = ['á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ö' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z'];
        $v = strtr($v, $map);

        return preg_replace('/\s+/', ' ', $v) ?? $v;
    }
}

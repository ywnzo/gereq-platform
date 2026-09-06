<?php

declare(strict_types=1);

namespace Modules\BikeService\Application\Catalog;

/**
 * Otevřená data Bike Index (bike_data) — výrobci rámů + typy komponent.
 * Cache CSV v DB (CatalogCacheStore); file cache jen jako nouzový fallback.
 * @see https://github.com/bikeindex/bike_data
 */
final class BikeDataCatalogClient
{
    private const MANUFACTURERS_URL = 'https://raw.githubusercontent.com/bikeindex/bike_data/main/data/manufacturers.csv';
    private const COMPONENTS_URL = 'https://raw.githubusercontent.com/bikeindex/bike_data/main/data/component_types.csv';
    private const BRAKES_URL = 'https://raw.githubusercontent.com/bikeindex/bike_data/main/data/brake_types.csv';
    private const WHEELS_URL = 'https://raw.githubusercontent.com/bikeindex/bike_data/main/data/wheel_sizes.csv';
    private const HANDLEBARS_URL = 'https://raw.githubusercontent.com/bikeindex/bike_data/main/data/handlebar_types.csv';

    private const TTL_SECONDS = 86400 * 7;

    private readonly string $cacheDir;

    public function __construct(
        private readonly CatalogCacheStore $cacheStore,
        ?string $cacheDir = null,
    ) {
        $this->cacheDir = $cacheDir ?? dirname(__DIR__, 4) . '/storage/cache/bikeservice';
    }

    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string, source: string}
     */
    public function manufacturers(string $query = '', ?bool $framesOnly = null, ?bool $ebikeOnly = null, int $limit = 200): array
    {
        $raw = $this->loadCsv(self::MANUFACTURERS_URL, 'manufacturers.csv');
        if (!$raw['ok']) {
            return ['ok' => false, 'items' => [], 'error' => $raw['error'], 'source' => 'bike_data'];
        }
        $q = mb_strtolower(trim($query));
        $items = [];
        foreach ($raw['rows'] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $makesFrames = $this->truthy($row['makes_frames'] ?? null);
            $ebike = $this->truthy($row['ebike_only'] ?? null);
            if ($framesOnly === true && !$makesFrames) {
                continue;
            }
            if ($ebikeOnly === true && !$ebike) {
                continue;
            }
            if ($q !== '') {
                $alt = mb_strtolower((string) ($row['alternate_name'] ?? ''));
                $hay = mb_strtolower($name);
                if (!str_contains($hay, $q) && !str_contains($alt, $q)) {
                    continue;
                }
            }
            $items[] = [
                'name' => $name,
                'alternate_name' => trim((string) ($row['alternate_name'] ?? '')) ?: null,
                'website' => trim((string) ($row['website'] ?? '')) ?: null,
                'makes_frames' => $makesFrames,
                'ebike_only' => $ebike,
                'open_year' => trim((string) ($row['open_year'] ?? '')) ?: null,
                'close_year' => trim((string) ($row['close_year'] ?? '')) ?: null,
                'logo_url' => trim((string) ($row['logo_url'] ?? '')) ?: null,
            ];
            if (count($items) >= max(1, min(2000, $limit))) {
                break;
            }
        }

        return ['ok' => true, 'items' => $items, 'error' => null, 'source' => 'bike_data'];
    }

    /**
     * @return array{ok: bool, groups: array<string, list<array<string, mixed>>>, items: list<array<string, mixed>>, error: ?string, source: string}
     */
    public function componentTypes(string $query = '', string $group = ''): array
    {
        $raw = $this->loadCsv(self::COMPONENTS_URL, 'component_types.csv');
        if (!$raw['ok']) {
            return ['ok' => false, 'groups' => [], 'items' => [], 'error' => $raw['error'], 'source' => 'bike_data'];
        }
        $q = mb_strtolower(trim($query));
        $groupFilter = trim($group);
        $items = [];
        $groups = [];
        foreach ($raw['rows'] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $g = trim((string) ($row['group'] ?? 'Other')) ?: 'Other';
            if ($groupFilter !== '' && strcasecmp($g, $groupFilter) !== 0) {
                continue;
            }
            $secondary = trim((string) ($row['secondary_name'] ?? ''));
            if ($q !== '') {
                $hay = mb_strtolower($name . ' ' . $secondary . ' ' . $g);
                if (!str_contains($hay, $q)) {
                    continue;
                }
            }
            $item = [
                'name' => $name,
                'secondary_name' => $secondary !== '' ? $secondary : null,
                'group' => $g,
                'has_multiple_locations' => $this->truthy($row['has_multiple_locations'] ?? null),
            ];
            $items[] = $item;
            $groups[$g][] = $item;
        }
        ksort($groups);

        return ['ok' => true, 'groups' => $groups, 'items' => $items, 'error' => null, 'source' => 'bike_data'];
    }

    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string}
     */
    public function brakeTypes(): array
    {
        return $this->simpleList(self::BRAKES_URL, 'brake_types.csv');
    }

    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string}
     */
    public function wheelSizes(): array
    {
        return $this->simpleList(self::WHEELS_URL, 'wheel_sizes.csv');
    }

    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string}
     */
    public function handlebarTypes(): array
    {
        return $this->simpleList(self::HANDLEBARS_URL, 'handlebar_types.csv');
    }

    /**
     * @return list<string>
     */
    public function componentGroups(): array
    {
        $result = $this->componentTypes();
        if (!$result['ok']) {
            return [];
        }

        return array_keys($result['groups']);
    }

    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string}
     */
    private function simpleList(string $url, string $cacheFile): array
    {
        $raw = $this->loadCsv($url, $cacheFile);
        if (!$raw['ok']) {
            return ['ok' => false, 'items' => [], 'error' => $raw['error']];
        }
        $items = [];
        foreach ($raw['rows'] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $items[] = array_merge(['name' => $name], $row);
        }

        return ['ok' => true, 'items' => $items, 'error' => null];
    }

    /**
     * @return array{ok: bool, rows: list<array<string, string>>, error: ?string}
     */
    private function loadCsv(string $url, string $cacheFile): array
    {
        $cacheKey = 'bike_data:' . $cacheFile;
        $cached = $this->cacheStore->get($cacheKey, self::TTL_SECONDS);
        if ($cached !== null && $cached !== '') {
            return ['ok' => true, 'rows' => $this->parseCsv($cached), 'error' => null];
        }

        $fetched = $this->download($url);
        if ($fetched !== null && $fetched !== '') {
            try {
                $this->cacheStore->put($cacheKey, $fetched);
            } catch (\Throwable) {
                $this->writeFileFallback($cacheFile, $fetched);
            }

            return ['ok' => true, 'rows' => $this->parseCsv($fetched), 'error' => null];
        }

        $stale = $this->cacheStore->getStale($cacheKey);
        if ($stale !== null && $stale !== '') {
            return ['ok' => true, 'rows' => $this->parseCsv($stale), 'error' => null];
        }

        $fileBody = $this->readFileFallback($cacheFile);
        if ($fileBody !== null) {
            return ['ok' => true, 'rows' => $this->parseCsv($fileBody), 'error' => null];
        }

        return ['ok' => false, 'rows' => [], 'error' => 'bikeservice.error.catalog_fetch'];
    }

    private function writeFileFallback(string $cacheFile, string $body): void
    {
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            return;
        }
        @file_put_contents(rtrim($this->cacheDir, '/') . '/' . $cacheFile, $body);
    }

    private function readFileFallback(string $cacheFile): ?string
    {
        $path = rtrim($this->cacheDir, '/') . '/' . $cacheFile;
        if (!is_file($path)) {
            return null;
        }
        $body = @file_get_contents($path);

        return is_string($body) && $body !== '' ? $body : null;
    }

    private function download(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: text/csv,text/plain,*/*',
                'User-Agent: GEREQ-BikeService/1.4 (catalog; +https://bikeindex.org)',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }

        return (string) $body;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return [];
        }
        fwrite($fh, $csv);
        rewind($fh);
        $header = fgetcsv($fh, 0, ',', '"', '\\');
        if (!is_array($header) || $header === []) {
            fclose($fh);

            return [];
        }
        $header = array_map(static fn ($h): string => trim((string) $h), $header);
        $rows = [];
        while (($data = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (!is_array($data) || $data === [null] || $data === []) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = (string) ($data[$i] ?? '');
            }
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $v = strtolower(trim((string) $value));

        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }
}

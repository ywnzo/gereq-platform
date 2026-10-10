<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

use Modules\Vikendo\Application\VikendoClient;
use Modules\Vikendo\Application\VikendoLog;
use Modules\Events\Domain\CatalogEvent;

/**
 * Import událostí z Vikendo.cz podle vybraných okresů (Admin → Vikendo).
 * Používá /api/events (district filtr funguje; event_listings ho ignoruje).
 */
final class VikendoSource implements EventSourceInterface
{
    /** @var list<string> */
    public const DEFAULT_DISTRICT_EIDS = [
        '3401', // Domažlice
        '3404', // Klatovy
        '3405', // Plzeň-město
        '3406', // Plzeň-jih
        '3407', // Plzeň-sever
        '3408', // Rokycany
        '3410', // Tachov
    ];

    /**
     * @param list<string> $districtEids
     * @param array<string, string> $districtNames eid → název (pro mapování regionu)
     */
    public function __construct(
        private readonly VikendoClient $client,
        private readonly string $key = 'vikendo',
        private readonly string $fallbackRegion = 'Plzeň-město',
        private readonly string $organizer = 'Vikendo',
        private readonly array $districtEids = self::DEFAULT_DISTRICT_EIDS,
        private readonly int $perPage = 100,
        private readonly int $maxPages = 40,
        private readonly ?string $startFrom = null,
        private readonly array $districtNames = [],
    ) {
    }

    /** @var array<string, string> */
    private array $resolvedUrlCache = [];

    public function key(): string
    {
        return $this->key;
    }

    public function fetch(): array
    {
        $out = [];
        $page = 1;
        $startFrom = $this->startFrom
            ?? (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        $districts = $this->districtEids !== []
            ? array_values($this->districtEids)
            : self::DEFAULT_DISTRICT_EIDS;

        VikendoLog::info('fetch.start', [
            'source' => $this->key,
            'start_from' => $startFrom,
            'districts' => $districts,
            'per_page' => $this->perPage,
            'max_pages' => $this->maxPages,
        ]);

        do {
            $t0 = microtime(true);
            // Okresní filtr stačí — nesvazovat na jeden kraj (umožní jiné okresy).
            $query = $this->client->listingQuery(
                regionEid: '',
                districtEids: $districts,
                startFrom: $startFrom,
                page: $page,
                perPage: $this->perPage,
            );
            // District filtr funguje na /api/events, ne na event_listings.
            try {
                $payload = $this->client->events($query);
            } catch (\Throwable $e) {
                VikendoLog::error('fetch.page.fail', [
                    'page' => $page,
                    'message' => $e->getMessage(),
                    'elapsed_s' => round(microtime(true) - $t0, 2),
                ]);
                throw $e;
            }
            $events = $payload['events'] ?? [];
            if (!is_array($events) || $events === []) {
                VikendoLog::info('fetch.page.empty', [
                    'page' => $page,
                    'elapsed_s' => round(microtime(true) - $t0, 2),
                ]);
                break;
            }
            $mapped = 0;
            $rawCount = 0;
            foreach ($events as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rawCount++;
                $normalized = $this->mapRow($row);
                if ($normalized !== null) {
                    $out[] = $normalized;
                    $mapped++;
                }
            }

            $pageInfo = is_array($payload['page'] ?? null) ? $payload['page'] : [];
            $size = max(1, (int) ($pageInfo['size'] ?? $this->perPage));
            $total = (int) ($pageInfo['count'] ?? 0);
            $totalPages = $total > 0 ? (int) ceil($total / $size) : $page;
            VikendoLog::info('fetch.page', [
                'page' => $page,
                'of' => min($totalPages, $this->maxPages),
                'api_total' => $total,
                'raw' => $rawCount,
                'mapped' => $mapped,
                'skipped_map' => $rawCount - $mapped,
                'cumulative' => count($out),
                'elapsed_s' => round(microtime(true) - $t0, 2),
            ]);
            $page++;
        } while ($page <= $totalPages && $page <= $this->maxPages);

        VikendoLog::info('fetch.done', [
            'pages' => $page - 1,
            'events' => count($out),
        ]);

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function mapRow(array $row): ?NormalizedEvent
    {
        $id = trim((string) ($row['id'] ?? ''));
        $title = TextUtil::decode((string) ($row['name'] ?? $row['title'] ?? ''));
        if ($id === '' || $title === '') {
            return null;
        }

        $starts = $this->parseDate((string) ($row['start_date'] ?? ''));
        if (!$starts instanceof \DateTimeImmutable) {
            return null;
        }
        $ends = $this->parseDate((string) ($row['end_date'] ?? ''));
        if ($ends instanceof \DateTimeImmutable && $ends <= $starts) {
            $ends = null;
        }

        $region = $this->resolveRegion($row);
        if ($region === null) {
            return null;
        }

        $muni = is_array($row['municipality'] ?? null) ? $row['municipality'] : [];
        $placeBlock = is_array($row['place'] ?? null) ? $row['place'] : [];
        $placeName = TextUtil::decode((string) ($placeBlock['name'] ?? ''));
        $muniName = TextUtil::decode((string) ($muni['name'] ?? ''));
        if ($placeName !== '' && $muniName !== '' && !str_contains(mb_strtolower($placeName), mb_strtolower($muniName))) {
            $place = $muniName . ' – ' . $placeName;
        } elseif ($placeName !== '') {
            $place = $placeName;
        } elseif ($muniName !== '') {
            $place = $muniName;
        } else {
            $place = $region;
        }

        $catLabels = [$title];
        foreach ($row['categories'] ?? [] as $cat) {
            if (is_array($cat) && isset($cat['name'])) {
                $catLabels[] = (string) $cat['name'];
            } elseif (is_string($cat)) {
                $catLabels[] = $cat;
            }
        }
        foreach ($row['category_names'] ?? [] as $cat) {
            if (is_string($cat)) {
                $catLabels[] = $cat;
            }
        }
        $category = CategoryMapper::map($catLabels, 'Kultura');

        $html = (string) ($row['description'] ?? '');
        $thumb = is_array($row['thumbnail'] ?? null) ? $row['thumbnail'] : [];
        $image = trim((string) ($thumb['url'] ?? ''));
        if ($image === '' && is_array($thumb['variants']['card'] ?? null)) {
            $image = trim((string) ($thumb['variants']['card']['url'] ?? ''));
        }
        $url = trim((string) ($row['url'] ?? ''));
        if ($url === '') {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug !== '') {
                $url = 'https://vikendo.cz/events/' . rawurlencode($slug);
            }
        }
        // Listing někdy vrací jen stránku Vikendo — dohledáme odkaz pořadatele.
        if ($url !== '' && VikendoClient::isVikendoHostUrl($url)) {
            $url = $this->resolveExternalUrl($url);
        }

        $timeLabel = $starts->format('H:i');
        if ($timeLabel === '00:00' || $timeLabel === '02:00') {
            // Vikendo často posílá „celodenní“ jako 02:00 (UTC offset artefact).
            $timeLabel = '';
        }

        return new NormalizedEvent(
            source: $this->key,
            sourceId: $id,
            title: $title,
            region: $region,
            place: $place,
            startsAt: $starts,
            endsAt: $ends,
            category: $category,
            description: TextUtil::plainFromHtml($html !== '' ? $html : $title),
            longDescription: TextUtil::sanitizeHtml($html),
            price: null,
            organizer: $this->organizer,
            externalUrl: $url !== '' ? $url : null,
            imageUrl: $image !== '' ? $image : null,
            timeLabel: $timeLabel,
            color: CategoryMapper::colorFor($category),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveRegion(array $row): ?string
    {
        $muni = is_array($row['municipality'] ?? null) ? $row['municipality'] : [];
        $district = is_array($row['district'] ?? null) ? $row['district'] : [];
        $candidates = [
            (string) ($muni['district_name'] ?? ''),
            (string) ($district['name'] ?? ''),
            (string) ($muni['name_formatted'] ?? ''),
        ];
        foreach ($candidates as $raw) {
            $mapped = $this->normalizeRegionLabel($raw);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        $fallback = $this->normalizeRegionLabel($this->fallbackRegion);

        return $fallback;
    }

    private function normalizeRegionLabel(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (in_array($raw, CatalogEvent::REGIONS, true)) {
            return $raw;
        }
        // "Plzeň (Plzeň-město)" → Plzeň-město
        if (preg_match('/\(([^)]+)\)\s*$/u', $raw, $m) === 1) {
            $inner = trim($m[1]);
            if (in_array($inner, CatalogEvent::REGIONS, true)) {
                return $inner;
            }
            if ($inner !== '') {
                return $inner;
            }
        }
        $lower = mb_strtolower($raw);
        foreach (CatalogEvent::REGIONS as $region) {
            if (mb_strtolower($region) === $lower) {
                return $region;
            }
        }
        foreach ($this->districtNames as $name) {
            if (mb_strtolower($name) === $lower) {
                return $name;
            }
        }

        // Jiné okresy mimo výchozí KdeAco seznam — ponech název z Vikendo.
        return $raw;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function resolveExternalUrl(string $vikendoUrl): string
    {
        if (isset($this->resolvedUrlCache[$vikendoUrl])) {
            return $this->resolvedUrlCache[$vikendoUrl];
        }
        $resolved = $this->client->resolveSourceUrl($vikendoUrl);
        $this->resolvedUrlCache[$vikendoUrl] = $resolved;
        if ($resolved !== $vikendoUrl) {
            VikendoLog::info('url.resolved', [
                'from' => $vikendoUrl,
                'to' => $resolved,
            ]);
        }

        return $resolved;
    }
}

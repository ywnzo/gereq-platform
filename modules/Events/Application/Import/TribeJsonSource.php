<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

/** The Events Calendar (Tribe) REST JSON API — configurable URL + category. */
final class TribeJsonSource implements EventSourceInterface
{
    public function __construct(
        private readonly string $key,
        private readonly string $url,
        private readonly string $region,
        private readonly string $organizer = 'MKS',
        private readonly string $categorySlug = 'mks',
        private readonly int $perPage = 50,
        private readonly HttpFetcher $http = new HttpFetcher(),
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function fetch(): array
    {
        $out = [];
        $page = 1;
        $totalPages = 1;
        $base = $this->endpointBase();
        do {
            $sep = str_contains($base, '?') ? '&' : '?';
            $url = $base . $sep . http_build_query([
                'categories' => $this->categorySlug,
                'per_page' => $this->perPage,
                'page' => $page,
            ]);
            $payload = $this->http->getJson($url);
            $totalPages = max(1, (int) ($payload['total_pages'] ?? 1));
            $events = $payload['events'] ?? [];
            if (!is_array($events)) {
                break;
            }
            foreach ($events as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $normalized = $this->mapRow($row);
                if ($normalized !== null) {
                    $out[] = $normalized;
                }
            }
            $page++;
        } while ($page <= $totalPages);

        return $out;
    }

    private function endpointBase(): string
    {
        $url = trim($this->url);
        // Allow pasting listing URL or full REST endpoint.
        if (!str_contains($url, '/wp-json/')) {
            $url = rtrim($url, '/') . '/wp-json/tribe/events/v1/events';
        }

        return preg_replace('/\?.*$/', '', $url) ?? $url;
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): ?NormalizedEvent
    {
        $id = trim((string) ($row['id'] ?? ''));
        $title = TextUtil::decode((string) ($row['title'] ?? ''));
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

        $venue = is_array($row['venue'] ?? null) ? $row['venue'] : [];
        $venueName = TextUtil::decode((string) ($venue['venue'] ?? $this->organizer));
        $place = $venueName !== ''
            ? (str_contains(mb_strtolower($venueName), mb_strtolower($this->region))
                ? $venueName
                : $this->region . ' – ' . $venueName)
            : $this->region;

        $catLabels = [];
        foreach ($row['categories'] ?? [] as $cat) {
            if (is_array($cat) && isset($cat['name'])) {
                $catLabels[] = (string) $cat['name'];
            }
        }
        $category = CategoryMapper::map($catLabels, 'Kultura');
        $html = (string) ($row['description'] ?? '');
        $image = is_array($row['image'] ?? null) && !empty($row['image']['url'])
            ? (string) $row['image']['url']
            : null;
        $cost = TextUtil::decode((string) ($row['cost'] ?? ''));
        $url = trim((string) ($row['url'] ?? ''));

        return new NormalizedEvent(
            source: $this->key,
            sourceId: $id,
            title: $title,
            region: $this->region,
            place: $place,
            startsAt: $starts,
            endsAt: $ends,
            category: $category,
            description: TextUtil::plainFromHtml($html !== '' ? $html : $title),
            longDescription: TextUtil::sanitizeHtml($html),
            price: $cost !== '' ? $cost : null,
            organizer: $this->organizer,
            externalUrl: $url !== '' ? $url : null,
            imageUrl: $image,
            timeLabel: $starts->format('H:i') !== '00:00' ? $starts->format('H:i') : '',
            color: CategoryMapper::colorFor($category),
        );
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $tz = new \DateTimeZone('Europe/Prague');
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $tz);
        if ($dt instanceof \DateTimeImmutable) {
            return $dt;
        }
        try {
            return new \DateTimeImmutable($value, $tz);
        } catch (\Exception) {
            return null;
        }
    }
}

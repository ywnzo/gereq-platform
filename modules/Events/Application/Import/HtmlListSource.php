<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

/** HTML event-card list scrape + optional detail enrichment, with optional field map. */
final class HtmlListSource implements EventSourceInterface
{
    private readonly HtmlScrapeMap $map;

    /** @param array<string, mixed>|HtmlScrapeMap|null $map */
    public function __construct(
        private readonly string $key,
        private readonly string $url,
        private readonly string $region,
        private readonly string $organizer = 'MKS',
        private readonly int $maxPages = 8,
        private readonly bool $fetchDetails = true,
        array|HtmlScrapeMap|null $map = null,
        private readonly HttpFetcher $http = new HttpFetcher(),
    ) {
        $this->map = $map instanceof HtmlScrapeMap
            ? $map
            : new HtmlScrapeMap(is_array($map) ? $map : []);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function fetch(): array
    {
        $seen = [];
        $out = [];
        $base = $this->listBase();
        $origin = $this->origin();
        $pageParam = $this->map->get('page_param') ?: 'strana';
        for ($page = 1; $page <= $this->maxPages; $page++) {
            $url = $page === 1 ? $base : $base . (str_contains($base, '?') ? '&' : '?') . rawurlencode($pageParam) . '=' . $page;
            $html = $this->http->get($url);
            $rows = $this->parseList($html, $origin);
            if ($rows === []) {
                break;
            }
            $added = 0;
            foreach ($rows as $row) {
                if (isset($seen[$row->sourceId])) {
                    continue;
                }
                $seen[$row->sourceId] = true;
                if ($this->fetchDetails && $row->externalUrl) {
                    try {
                        $row = $this->enrichFromDetail($row);
                    } catch (\Throwable) {
                        // keep list-card data when detail fails
                    }
                }
                $out[] = $row;
                $added++;
            }
            if ($added === 0) {
                break;
            }
        }

        return $out;
    }

    /** @return list<NormalizedEvent> */
    public function parseList(string $html, ?string $origin = null): array
    {
        $origin ??= $this->origin();
        $card = $this->map->get('card');
        $parts = @preg_split('/' . $card . '/u', $html) ?: [];
        if ($parts === [] || count($parts) < 2) {
            return [];
        }
        array_shift($parts);
        $out = [];
        foreach ($parts as $chunk) {
            $chunk = substr($chunk, 0, 8000);
            $href = $this->matchGroup($this->map->get('link'), $chunk, 1);
            $titleRaw = $this->matchGroup($this->map->get('title'), $chunk, 1);
            $title = $titleRaw !== null ? TextUtil::decode(strip_tags($titleRaw)) : null;
            if ($href === null || $title === null || $title === '') {
                continue;
            }
            if (!str_starts_with($href, 'http')) {
                // relative ok
            } else {
                // absolute link — keep path-ish source id later
            }

            $typeRaw = $this->matchGroup($this->map->get('type'), $chunk, 1);
            $type = $typeRaw !== null ? TextUtil::decode(strip_tags($typeRaw)) : '';
            $dateRaw = $this->textItem($chunk, $this->map->get('date_class'));
            $timeRaw = $this->textItem($chunk, $this->map->get('time_class'));
            $placeRaw = $this->textItem($chunk, $this->map->get('place_class')) ?: $this->region;
            $priceRaw = $this->textItem($chunk, $this->map->get('price_class'));
            $imageRaw = $this->matchGroup($this->map->get('image'), $chunk, 1);
            $image = $imageRaw !== null ? $this->upgradeImageUrl($imageRaw) : null;

            $starts = $this->parseCzechDateTime($dateRaw, $timeRaw);
            if (!$starts instanceof \DateTimeImmutable) {
                continue;
            }
            $ends = $this->parseEndFromTimeRange($starts, $timeRaw);
            $path = (string) (parse_url($href, PHP_URL_PATH) ?: $href);
            $slug = preg_replace('#\.html$#', '', basename($path)) ?: basename($path);
            $category = CategoryMapper::map([$type, $title], 'Kultura');
            $place = str_contains(mb_strtolower($placeRaw), mb_strtolower($this->region))
                ? $placeRaw
                : $this->region . ' – ' . $placeRaw;
            $external = str_starts_with($href, 'http') ? $href : rtrim($origin, '/') . $href;

            $out[] = new NormalizedEvent(
                source: $this->key,
                sourceId: $slug,
                title: $title,
                region: $this->region,
                place: $place,
                startsAt: $starts,
                endsAt: $ends,
                category: $category,
                description: $type !== '' ? $type . ' — ' . $title : $title,
                longDescription: null,
                price: $priceRaw !== null && $priceRaw !== '' ? $priceRaw : null,
                organizer: $this->organizer,
                externalUrl: $external,
                imageUrl: $image,
                timeLabel: $this->timeLabel($timeRaw),
                color: CategoryMapper::colorFor($category),
            );
        }

        return $out;
    }

    public function enrichFromDetail(NormalizedEvent $row): NormalizedEvent
    {
        $url = (string) $row->externalUrl;
        if ($url === '') {
            return $row;
        }
        $html = $this->http->get($url);
        $parsed = $this->parseDetail($html);
        $description = $parsed['teaser'] !== '' ? $parsed['teaser'] : $row->description;
        $long = $parsed['bodyHtml'] ?? $row->longDescription;
        $image = $parsed['posterUrl'] ?? $parsed['heroUrl'] ?? $row->imageUrl;
        if ($image) {
            $image = $this->upgradeImageUrl($image);
        }

        return new NormalizedEvent(
            source: $row->source,
            sourceId: $row->sourceId,
            title: $row->title,
            region: $row->region,
            place: $row->place,
            startsAt: $row->startsAt,
            endsAt: $row->endsAt,
            category: $row->category,
            description: $description,
            longDescription: $long,
            price: $row->price,
            organizer: $row->organizer,
            externalUrl: $row->externalUrl,
            imageUrl: $image,
            timeLabel: $row->timeLabel,
            color: $row->color,
            gallery: $parsed['gallery'],
        );
    }

    /**
     * @return array{
     *   teaser: string,
     *   bodyHtml: ?string,
     *   heroUrl: ?string,
     *   posterUrl: ?string,
     *   gallery: list<array<string, mixed>>
     * }
     */
    public function parseDetail(string $html): array
    {
        $teaser = '';
        if (preg_match('#property="og:description"\s+content="([^"]*)"#i', $html, $m) === 1
            || preg_match('#content="([^"]*)"\s+property="og:description"#i', $html, $m) === 1) {
            $teaser = TextUtil::decode($m[1]);
        }
        if ($teaser === '') {
            $teaserRaw = $this->matchGroup($this->map->get('detail_teaser'), $html, 1);
            if ($teaserRaw !== null) {
                $teaser = TextUtil::decode(strip_tags($teaserRaw));
            }
        }

        $bodyHtml = null;
        $bodyRaw = $this->matchGroup($this->map->get('detail_body'), $html, 1);
        if ($bodyRaw !== null) {
            $inner = trim($bodyRaw);
            $inner = preg_replace('#^<h2[^>]*>.*?</h2>#su', '', $inner) ?? $inner;
            $bodyHtml = TextUtil::sanitizeHtml('<div class="imported-body">' . $inner . '</div>');
            if ($teaser === '' && $bodyHtml !== null) {
                $teaser = TextUtil::plainFromHtml($bodyHtml, 320);
            }
        }

        $heroUrl = null;
        if (preg_match('#property="og:image"\s+content="(https?://[^"]+)"#i', $html, $m) === 1
            || preg_match('#content="(https?://[^"]+)"\s+property="og:image"#i', $html, $m) === 1) {
            $heroUrl = $m[1];
        }

        $gallery = [];
        $posterUrl = null;
        $galleryMarker = preg_quote($this->map->get('detail_gallery'), '#');
        $galleryChunk = null;
        if (preg_match('#' . $galleryMarker . '[^>]*>([\s\S]*?)</div>\s*</div>\s*</section>#u', $html, $gm) === 1) {
            $galleryChunk = $gm[1];
        } elseif (preg_match('#' . $galleryMarker . '[^>]*>([\s\S]{0,20000})#u', $html, $gm) === 1) {
            $galleryChunk = $gm[1];
        }
        if ($galleryChunk !== null && preg_match_all('#<img[^>]+>#iu', $galleryChunk, $imgs) > 0) {
            $posterAlt = $this->map->get('poster_alt');
            foreach ($imgs[0] as $tag) {
                $hi = null;
                if (preg_match('#data-ngsrc="(https?://[^"]+)"#i', $tag, $um) === 1) {
                    $hi = $um[1];
                } elseif (preg_match('#src="(https?://[^"]+)"#i', $tag, $um) === 1) {
                    $hi = $um[1];
                }
                if ($hi === null) {
                    continue;
                }
                $alt = '';
                if (preg_match('#alt="([^"]*)"#i', $tag, $am) === 1) {
                    $alt = TextUtil::decode($am[1]);
                }
                $hi = $this->upgradeImageUrl($hi);
                if ($posterUrl === null && $posterAlt !== '' && preg_match('/' . $posterAlt . '/iu', $alt) === 1) {
                    $posterUrl = $hi;
                }
                $gallery[] = [
                    'kind' => 'photo',
                    'url' => $hi,
                    'title' => $alt !== '' ? $alt : 'Foto',
                ];
            }
        }

        if (preg_match('#<iframe[^>]+src="(https?://(?:www\.)?youtube\.com/embed/[^"]+)"#i', $html, $vm) === 1) {
            $yt = html_entity_decode($vm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $yt = preg_replace('/\?.*$/', '', $yt) ?? $yt;
            if (preg_match('#/embed/([A-Za-z0-9_-]+)#', $yt, $idm) === 1) {
                array_unshift($gallery, [
                    'kind' => 'video',
                    'url' => 'https://www.youtube.com/watch?v=' . $idm[1],
                    'title' => 'Trailer',
                ]);
            }
        }

        return [
            'teaser' => $teaser,
            'bodyHtml' => $bodyHtml,
            'heroUrl' => $heroUrl,
            'posterUrl' => $posterUrl ?? $heroUrl,
            'gallery' => $gallery,
        ];
    }

    private function matchGroup(string $pattern, string $haystack, int $group): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }
        $ok = @preg_match('#' . $pattern . '#su', $haystack, $m);
        if ($ok !== 1 || !isset($m[$group])) {
            return null;
        }

        return (string) $m[$group];
    }

    private function upgradeImageUrl(string $url): string
    {
        $up = preg_replace('#/rimg/(\d+)/\d+x\d+/#', '/rimg/$1/1200x0/', $url);

        return is_string($up) && $up !== '' ? $up : $url;
    }

    private function listBase(): string
    {
        return preg_replace('/\?.*$/', '', trim($this->url)) ?: trim($this->url);
    }

    private function origin(): string
    {
        $parts = parse_url($this->url);

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'www.mkstc.cz');
    }

    private function textItem(string $chunk, string $class): ?string
    {
        $class = trim($class);
        if ($class === '') {
            return null;
        }
        if (preg_match('#class="[^"]*' . preg_quote($class, '#') . '[^"]*"[\s\S]*?<div class="text">(.*?)</div>#u', $chunk, $m) !== 1) {
            return null;
        }

        return TextUtil::decode(strip_tags($m[1]));
    }

    private function parseCzechDateTime(?string $dateRaw, ?string $timeRaw): ?\DateTimeImmutable
    {
        $dateRaw = trim((string) $dateRaw);
        if ($dateRaw === '' || preg_match('/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $dateRaw, $m) !== 1) {
            return null;
        }
        [$hour, $minute] = [0, 0];
        if (preg_match('/(\d{1,2})[.:](\d{2})/', (string) $timeRaw, $tm) === 1) {
            $hour = (int) $tm[1];
            $minute = (int) $tm[2];
        }
        $tz = new \DateTimeZone('Europe/Prague');

        try {
            return (new \DateTimeImmutable('now', $tz))
                ->setDate((int) $m[3], (int) $m[2], (int) $m[1])
                ->setTime($hour, $minute);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseEndFromTimeRange(\DateTimeImmutable $starts, ?string $timeRaw): ?\DateTimeImmutable
    {
        if (preg_match('/(\d{1,2})[.:](\d{2})\s*[-–—]\s*(\d{1,2})[.:](\d{2})/u', (string) $timeRaw, $m) !== 1) {
            return null;
        }
        $end = $starts->setTime((int) $m[3], (int) $m[4]);

        return $end > $starts ? $end : null;
    }

    private function timeLabel(?string $timeRaw): string
    {
        $timeRaw = trim((string) $timeRaw);

        return $timeRaw === '' ? '' : str_replace('.', ':', $timeRaw);
    }
}

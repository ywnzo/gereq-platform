<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

/** Municipal ASP calendar (ŠumavaNet) — configurable base URL + venue codes. */
final class KlatovyAspSource implements EventSourceInterface
{
    /** @param list<string> $venues */
    public function __construct(
        private readonly string $key = 'klatovy',
        private readonly string $url = 'https://www.klatovy.cz/klatovy/kalendar-akci.asp',
        private readonly string $region = 'Klatovy',
        private readonly string $organizer = 'MKS Klatovy',
        private readonly array $venues = ['KT_KD'],
        private readonly HttpFetcher $http = new HttpFetcher(),
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function fetch(): array
    {
        $seen = [];
        $out = [];
        $base = preg_replace('/\?.*$/', '', trim($this->url)) ?: trim($this->url);
        foreach ($this->venues as $venue) {
            $html = $this->http->get($base . '?misto_akce=' . rawurlencode($venue), 'Windows-1250');
            foreach ($this->parseHtml($html) as $row) {
                if (isset($seen[$row->sourceId])) {
                    continue;
                }
                $seen[$row->sourceId] = true;
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @return list<NormalizedEvent> */
    public function parseHtml(string $html): array
    {
        if (!preg_match_all('#<tr>\s*<td class=\'akcem_d_[^\']* akce-bd\'\s*>(.*?)</td>\s*<td class=\'akcem_m_[^\']* akce-bm\'>(.*?)</td>\s*</tr>\s*<tr>\s*<td colspan=\'3\' class=\'akcem_t_[^\']* akce-bt\'>(.*?)</td>\s*</tr>\s*<tr>\s*<td colspan=\'3\' class=\'akcem_b_[^\']* akce-bb\'>(.*?)</td>\s*</tr>#su', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $base = preg_replace('/\?.*$/', '', trim($this->url)) ?: trim($this->url);
        $out = [];
        foreach ($matches as $m) {
            $dateCell = $m[1];
            $place = TextUtil::decode(strip_tags($m[2]));
            $title = TextUtil::decode(strip_tags($m[3]));
            $body = $m[4];
            if ($title === '') {
                continue;
            }

            $startDay = preg_match('#class="akce-day1">\s*([^<]+)#u', $dateCell, $dm) === 1 ? trim($dm[1]) : null;
            $endDay = preg_match('#class="akce-day2">\s*([^<]+)#u', $dateCell, $dm) === 1 ? trim($dm[1]) : null;
            $time = preg_match('/,\s*(\d{1,2}:\d{2})/u', strip_tags($dateCell), $tm) === 1 ? $tm[1] : null;
            $starts = $this->parseDay($startDay, $time);
            if (!$starts instanceof \DateTimeImmutable) {
                continue;
            }
            $ends = $endDay !== null ? $this->parseDay($endDay, null) : null;
            if ($ends instanceof \DateTimeImmutable && $ends < $starts) {
                $ends = null;
            }

            $short = preg_match('#class="akce-mbodyab">(.*?)</div>#su', $body, $bm) === 1
                ? TextUtil::plainFromHtml($bm[1], 500) : '';
            $longHtml = null;
            if (preg_match('#class="akce-mbody">(.*?)</div>#su', $body, $bm) === 1) {
                $longHtml = TextUtil::sanitizeHtml('<p>' . $bm[1] . '</p>');
            } elseif ($short !== '') {
                $longHtml = TextUtil::sanitizeHtml('<p>' . htmlspecialchars($short, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>');
            }

            $image = $this->attachmentImageUrl($body);
            $gallery = $this->galleryFromBody($body);
            if ($image === null && $gallery !== []) {
                foreach ($gallery as $item) {
                    if (($item['kind'] ?? '') === 'video' && !empty($item['poster'])) {
                        $image = (string) $item['poster'];
                        break;
                    }
                }
            }
            // Placeholder rows on the municipal calendar (title only, no copy/poster/video).
            if ($this->isStubEvent($title, $short, $longHtml, $image, $gallery)) {
                continue;
            }
            $organizer = $this->organizer;
            if (preg_match('#class=[\'"]akce-autor[\'"]>\s*\(Zapsal:\s*([^,)]+)#u', $body, $om) === 1) {
                $organizer = TextUtil::decode(trim($om[1])) ?: $organizer;
            }

            $category = CategoryMapper::map([$title, $place, $short], 'Kultura');
            $sourceId = substr(sha1(mb_strtolower($title) . '|' . $starts->format('Y-m-d H:i') . '|' . mb_strtolower($place)), 0, 24);
            $placeLabel = str_contains(mb_strtolower($place), mb_strtolower($this->region))
                ? $place
                : $this->region . ' – ' . $place;

            $out[] = new NormalizedEvent(
                source: $this->key,
                sourceId: $sourceId,
                title: $title,
                region: $this->region,
                place: $placeLabel,
                startsAt: $starts,
                endsAt: $ends,
                category: $category,
                description: $short !== '' ? $short : $title,
                longDescription: $longHtml,
                price: $this->extractPrice($short . ' ' . strip_tags((string) $longHtml)),
                organizer: $organizer,
                externalUrl: $base . '?misto_akce=' . rawurlencode($this->venues[0] ?? 'KT_KD'),
                imageUrl: $image,
                timeLabel: $time ?? '',
                color: CategoryMapper::colorFor($category),
                gallery: $gallery,
            );
        }

        return $out;
    }

    private function parseDay(?string $day, ?string $time): ?\DateTimeImmutable
    {
        $day = trim((string) $day);
        if ($day === '' || preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $day, $m) !== 1) {
            return null;
        }
        $y = (int) $m[3];
        if ($y < 100) {
            $y += 2000;
        }
        $hour = 0;
        $minute = 0;
        if ($time !== null && preg_match('/^(\d{1,2}):(\d{2})$/', $time, $tm) === 1) {
            $hour = (int) $tm[1];
            $minute = (int) $tm[2];
        }
        $tz = new \DateTimeZone('Europe/Prague');

        try {
            return (new \DateTimeImmutable('now', $tz))->setDate($y, (int) $m[2], (int) $m[1])->setTime($hour, $minute);
        } catch (\Exception) {
            return null;
        }
    }

    private function extractPrice(string $text): ?string
    {
        return preg_match('/(\d[\d\s]*\s*Kč)/u', $text, $m) === 1 ? TextUtil::decode($m[1]) : null;
    }

    /**
     * @param list<array<string, mixed>> $gallery
     */
    private function isStubEvent(string $title, string $short, ?string $longHtml, ?string $image, array $gallery = []): bool
    {
        if (($image !== null && $image !== '') || $gallery !== []) {
            return false;
        }
        $plainLong = $longHtml !== null ? trim(strip_tags($longHtml)) : '';
        if (mb_strlen($short) >= 40 || mb_strlen($plainLong) >= 40) {
            return false;
        }
        $t = mb_strtolower(trim($title));
        if (in_array($t, ['stand up show', 'koncert kd', 'koncert', 'akce'], true)) {
            return true;
        }
        // Generic title == short blurb, nothing else to show.
        return $short === '' || mb_strtolower($short) === $t;
    }

    /**
     * YouTube (and similar) links pasted into the ASP body as plain text, e.g. "Videoklip: https://youtu.be/…".
     *
     * @return list<array<string, mixed>>
     */
    private function galleryFromBody(string $body): array
    {
        $plain = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match_all('#https?://(?:www\.)?(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})[^\s<]*#iu', $plain, $matches) < 1) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($matches[1] as $id) {
            $id = trim($id);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'kind' => 'video',
                'url' => 'https://www.youtube.com/watch?v=' . $id,
                'title' => 'Videoklip',
                'poster' => 'https://img.youtube.com/vi/' . $id . '/hqdefault.jpg',
            ];
        }

        return $out;
    }

    /** Prefer raster attachment; fall back to PDF flyer (cover extracted on import). */
    private function attachmentImageUrl(string $body): ?string
    {
        if (preg_match_all('#akce-priloha\d*[^>]*>\s*<a[^>]*href="(https?://[^"]+)"#iu', $body, $matches) < 1) {
            // some rows put class before href / odd whitespace
            if (preg_match_all('#href="(https?://[^"]+)"[^>]*>(?:\s*)Příloha#iu', $body, $matches) < 1) {
                return null;
            }
        }
        $urls = $matches[1];
        foreach ($urls as $url) {
            if (preg_match('/\.(jpe?g|png|gif|webp)(\?|$)/i', $url) === 1) {
                return $url;
            }
        }
        foreach ($urls as $url) {
            if (preg_match('/\.pdf(\?|$)/i', $url) === 1) {
                return $url;
            }
        }

        return null;
    }
}

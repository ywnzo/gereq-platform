<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

/** Generic iCalendar (.ics) feed reader. */
final class IcalSource implements EventSourceInterface
{
    public function __construct(
        private readonly string $key,
        private readonly string $url,
        private readonly string $region,
        private readonly ?string $organizer = null,
        private readonly HttpFetcher $http = new HttpFetcher(),
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function fetch(): array
    {
        $raw = $this->http->get($this->url);
        $raw = $this->unfold($raw);

        return $this->parse($raw);
    }

    /** @return list<NormalizedEvent> */
    public function parse(string $ics): array
    {
        $blocks = preg_split('/BEGIN:VEVENT/i', $ics) ?: [];
        array_shift($blocks);
        $out = [];
        foreach ($blocks as $block) {
            $end = stripos($block, 'END:VEVENT');
            if ($end !== false) {
                $block = substr($block, 0, $end);
            }
            $fields = $this->fields($block);
            $title = TextUtil::decode($fields['SUMMARY'] ?? '');
            $uid = trim($fields['UID'] ?? '');
            $starts = $this->parseIcalDate($fields['DTSTART'] ?? '');
            if ($title === '' || !$starts instanceof \DateTimeImmutable) {
                continue;
            }
            if ($uid === '') {
                $uid = substr(sha1($title . '|' . $starts->format('c')), 0, 24);
            }
            $ends = $this->parseIcalDate($fields['DTEND'] ?? '');
            if ($ends instanceof \DateTimeImmutable && $ends <= $starts) {
                $ends = null;
            }
            $placeRaw = TextUtil::decode($fields['LOCATION'] ?? '');
            $place = $placeRaw !== ''
                ? (str_contains(mb_strtolower($placeRaw), mb_strtolower($this->region))
                    ? $placeRaw
                    : $this->region . ' – ' . $placeRaw)
                : $this->region;
            $description = TextUtil::decode(str_replace('\\n', "\n", $fields['DESCRIPTION'] ?? ''));
            $url = trim($fields['URL'] ?? $this->url);
            $category = CategoryMapper::map([$title, $description, $fields['CATEGORIES'] ?? ''], 'Kultura');
            $image = $this->imageFromFields($fields, $block);

            $out[] = new NormalizedEvent(
                source: $this->key,
                sourceId: $uid,
                title: $title,
                region: $this->region,
                place: $place,
                startsAt: $starts,
                endsAt: $ends,
                category: $category,
                description: $description !== '' ? TextUtil::plainFromHtml($description) : $title,
                longDescription: $description !== ''
                    ? TextUtil::sanitizeHtml('<p>' . nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>')
                    : null,
                price: null,
                organizer: $this->organizer,
                externalUrl: $url !== '' ? $url : null,
                imageUrl: $image,
                timeLabel: $starts->format('H:i') !== '00:00' ? $starts->format('H:i') : '',
                color: CategoryMapper::colorFor($category),
            );
        }

        return $out;
    }

    private function unfold(string $ics): string
    {
        return preg_replace("/\r\n[ \t]|\n[ \t]|\r[ \t]/", '', $ics) ?? $ics;
    }

    /**
     * @param array<string, string> $fields
     */
    private function imageFromFields(array $fields, string $block): ?string
    {
        foreach (['ATTACH', 'IMAGE', 'X-WP-IMAGES-URL', 'X-GOOGLE-CALENDAR-CONTENT-URL'] as $key) {
            $candidate = $this->asImageUrl($fields[$key] ?? '');
            if ($candidate !== null) {
                return $candidate;
            }
        }
        // Prefer image ATTACH when the first ATTACH is not an image (e.g. PDF).
        if (preg_match_all('/^ATTACH(?:;([^:]*))?:(.+)$/mi', $block, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $params = strtolower($m[1] ?? '');
                $url = $this->asImageUrl(trim($m[2]));
                if ($url === null) {
                    continue;
                }
                if ($params === '' || str_contains($params, 'image/') || preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $url) === 1) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function asImageUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('#https?://[^\s<>"]+#i', $value, $m) !== 1) {
            return null;
        }
        $url = rtrim($m[0], '.,;)');
        if (preg_match('/\.(pdf|docx?|xlsx?|zip)(\?|$)/i', $url) === 1) {
            return null;
        }

        return $url;
    }

    /** @return array<string, string> */
    private function fields(string $block): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $block) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtoupper(explode(';', $name, 2)[0]);
            $value = str_replace(['\\,', '\\;', '\\\\'], [',', ';', '\\'], $value);
            if (!isset($out[$name])) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    private function parseIcalDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // DTSTART;TZID=Europe/Prague:20261010T190000 or 20261010T190000Z or 20261010
        if (str_contains($value, ':')) {
            $value = substr($value, strrpos($value, ':') + 1);
        }
        $tz = new \DateTimeZone('Europe/Prague');
        $utc = str_ends_with($value, 'Z');
        $value = rtrim($value, 'Z');
        foreach (['Ymd\THis', 'Ymd\THi', 'Ymd'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $value, $utc ? new \DateTimeZone('UTC') : $tz);
            if ($dt instanceof \DateTimeImmutable) {
                return $utc ? $dt->setTimezone($tz) : $dt;
            }
        }

        try {
            return new \DateTimeImmutable($value, $tz);
        } catch (\Exception) {
            return null;
        }
    }
}

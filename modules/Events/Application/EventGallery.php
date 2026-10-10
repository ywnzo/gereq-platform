<?php

declare(strict_types=1);

namespace Modules\Events\Application;

/** Normalizes event gallery items (photo / gif / video) for admin + public API. */
final class EventGallery
{
    public const KINDS = ['photo', 'gif', 'video'];

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::item($row);
            if ($item !== null) {
                $out[] = $item;
            }
        }

        return array_values($out);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function publicItems(array $items): array
    {
        $out = [];
        foreach (self::normalize($items) as $item) {
            $out[] = self::publicItem($item);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function publicItem(array $item): array
    {
        $kind = (string) ($item['kind'] ?? 'photo');
        $url = (string) ($item['url'] ?? '');
        $embed = self::embedUrl($url, $item);
        $player = self::player($kind, $url, $embed);

        return [
            'kind' => $kind,
            'url' => $url,
            'title' => (string) ($item['title'] ?? ''),
            'caption' => (string) ($item['caption'] ?? ''),
            'poster' => (string) ($item['poster'] ?? ''),
            'start' => isset($item['start']) ? (int) $item['start'] : null,
            'end' => isset($item['end']) ? (int) $item['end'] : null,
            'embed_url' => $embed,
            'player' => $player,
            'media_asset_id' => $item['media_asset_id'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function item(array $row): ?array
    {
        $url = self::safeUrl((string) ($row['url'] ?? $row['src'] ?? ''));
        $assetId = self::assetId((string) ($row['media_asset_id'] ?? $row['asset_id'] ?? ''));
        if ($url === null && $assetId !== null) {
            $url = EventMediaLibrary::publicUrl($assetId);
        }
        if ($url === null) {
            return null;
        }
        if (self::isAudioUrl($url)) {
            return null;
        }
        $kind = strtolower(trim((string) ($row['kind'] ?? $row['type'] ?? 'photo')));
        if ($kind === 'song' || $kind === 'audio' || $kind === 'clip') {
            return null;
        }
        if ($kind === 'image' || $kind === 'photo') {
            $kind = 'photo';
        } elseif ($kind === 'giphy' || $kind === 'gif') {
            $kind = 'gif';
        } elseif ($kind === 'youtube' || $kind === 'vimeo' || $kind === 'video') {
            $kind = 'video';
        }
        if (!in_array($kind, self::KINDS, true)) {
            $kind = self::guessKind($url);
        }
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }
        $title = trim(strip_tags((string) ($row['title'] ?? $row['label'] ?? '')));
        $caption = trim(strip_tags((string) ($row['caption'] ?? '')));
        $poster = self::safeUrl((string) ($row['poster'] ?? '')) ?? '';
        $start = self::seconds($row['start'] ?? null);
        $end = self::seconds($row['end'] ?? null);
        $item = [
            'kind' => $kind,
            'url' => $url,
            'title' => mb_substr($title, 0, 190),
            'caption' => mb_substr($caption, 0, 500),
        ];
        if ($assetId !== null) {
            $item['media_asset_id'] = $assetId;
        }
        if ($poster !== '') {
            $item['poster'] = $poster;
        }
        if ($start !== null) {
            $item['start'] = $start;
        }
        if ($end !== null) {
            $item['end'] = $end;
        }

        return $item;
    }

    private static function assetId(string $id): ?string
    {
        $id = trim($id);

        return preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1 ? $id : null;
    }

    private static function guessKind(string $url): string
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if (self::youtubeId($url) !== null || self::vimeoId($url) !== null) {
            return 'video';
        }
        if (str_ends_with($path, '.gif')) {
            return 'gif';
        }
        foreach (['.mp4', '.webm', '.ogv', '.mov', '.m4v'] as $ext) {
            if (str_ends_with($path, $ext)) {
                return 'video';
            }
        }

        return 'photo';
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function embedUrl(string $url, array $item): ?string
    {
        $yt = self::youtubeId($url);
        if ($yt !== null) {
            $query = ['rel' => '0'];
            $start = self::seconds($item['start'] ?? null) ?? self::queryInt($url, 'start') ?? self::queryInt($url, 't');
            $end = self::seconds($item['end'] ?? null) ?? self::queryInt($url, 'end');
            if ($start !== null) {
                $query['start'] = (string) $start;
            }
            if ($end !== null) {
                $query['end'] = (string) $end;
            }

            return 'https://www.youtube-nocookie.com/embed/' . $yt . '?' . http_build_query($query);
        }
        $vimeo = self::vimeoId($url);
        if ($vimeo !== null) {
            return 'https://player.vimeo.com/video/' . $vimeo;
        }

        return null;
    }

    private static function player(string $kind, string $url, ?string $embed): string
    {
        if ($embed !== null && str_contains($embed, 'youtube')) {
            return 'youtube';
        }
        if ($embed !== null && str_contains($embed, 'vimeo')) {
            return 'vimeo';
        }
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if ($kind === 'video' || preg_match('/\.(mp4|webm|ogv|mov|m4v)(\?|$)/', $path) === 1) {
            return 'video';
        }

        return 'image';
    }

    public static function youtubeId(string $url): ?string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if (!in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            return null;
        }
        if ($host === 'youtu.be') {
            $id = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');

            return self::videoId($id);
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('#/(embed|shorts|live)/([A-Za-z0-9_-]{6,})#', $path, $m) === 1) {
            return self::videoId($m[2]);
        }
        $query = [];
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);
        $id = (string) ($query['v'] ?? '');

        return self::videoId($id);
    }

    public static function vimeoId(string $url): ?string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if (!in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            return null;
        }
        if (preg_match('#/(\d{5,})#', (string) (parse_url($url, PHP_URL_PATH) ?? ''), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private static function videoId(string $id): ?string
    {
        $id = trim($id);

        return preg_match('/^[A-Za-z0-9_-]{6,32}$/', $id) === 1 ? $id : null;
    }

    private static function queryInt(string $url, string $key): ?int
    {
        $query = [];
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);
        if (!isset($query[$key])) {
            $frag = (string) (parse_url($url, PHP_URL_FRAGMENT) ?? '');
            parse_str($frag, $query);
        }
        $raw = $query[$key] ?? null;
        if ($raw === null) {
            return null;
        }
        if (is_string($raw) && preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $raw, $m) === 1 && $raw !== '') {
            return ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);
        }

        return self::seconds($raw);
    }

    private static function seconds(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $n = (int) $value;

            return $n >= 0 && $n < 86400 ? $n : null;
        }

        return null;
    }

    private static function isAudioUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return preg_match('/\.(mp3|ogg|oga|wav|m4a|aac|flac)(\?|$)/', $path) === 1;
    }

    private static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 800) {
            return null;
        }
        if (str_starts_with($url, '/media/')) {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }

        return $url;
    }
}

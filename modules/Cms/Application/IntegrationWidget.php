<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

final class IntegrationWidget
{
    /** @param array<string, mixed> $payload @return array{provider: string, url: string, title: string, widget_height: int} */
    public static function normalize(array $payload): array
    {
        $provider = in_array($payload['provider'] ?? '', ['google_maps', 'openstreetmap', 'calendly', 'tally'], true) ? $payload['provider'] : 'openstreetmap';
        return [
            'provider' => $provider,
            'url' => self::url($provider, trim((string) ($payload['url'] ?? ''))),
            'title' => mb_substr((string) ($payload['title'] ?? ''), 0, 300),
            'widget_height' => max(160, min(1600, (int) ($payload['widget_height'] ?? 420))),
        ];
    }

    public static function url(string $provider, string $value): string
    {
        $parts = parse_url($value);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || preg_match('~[\x00-\x20]~', $value) || str_contains($value, chr(92))) {
            return '';
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $allowed = match ($provider) {
            'google_maps' => in_array($host, ['www.google.com', 'maps.google.com'], true) && preg_match('~^/maps/embed(?:/|$)~', $path) === 1,
            'openstreetmap' => in_array($host, ['www.openstreetmap.org', 'openstreetmap.org'], true) && $path === '/export/embed.html',
            'calendly' => $host === 'calendly.com' && preg_match('~^/[\\w-]+(?:/[\\w-]+)?/?$~D', $path) === 1,
            'tally' => $host === 'tally.so' && preg_match('~^/embed/[\\w-]+/?$~D', $path) === 1,
            default => false,
        };
        return $allowed ? mb_substr($value, 0, 8000) : '';
    }
}

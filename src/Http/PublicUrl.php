<?php

declare(strict_types=1);

namespace Gereq\Http;

/** Public APP_URL / storefront origin for HTTPS (gereq-platform.dev) and local Vite. */
final class PublicUrl
{
    public static function app(): string
    {
        $url = trim((string) env_value('APP_URL', ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return rtrim($url, '/');
    }

    /**
     * Storefront origin. On HTTPS APP_URL (platform) prefer an https FRONTEND_ORIGIN
     * or the app URL itself so OAuth / OG never bounce to localhost.
     */
    public static function frontend(): string
    {
        $httpsFrontends = [];
        foreach (self::frontendList() as $origin) {
            if (str_starts_with(strtolower($origin), 'https://')) {
                $httpsFrontends[] = $origin;
            }
        }

        $app = self::app();
        if (str_starts_with(strtolower($app), 'https://')) {
            return $httpsFrontends[0] ?? $app;
        }

        $all = self::frontendList();
        if ($all !== []) {
            return $all[0];
        }

        return $app !== '' ? $app : 'http://127.0.0.1:5155';
    }

    /** @return list<string> */
    public static function frontendList(): array
    {
        $raw = trim((string) env_value('FRONTEND_ORIGIN', ''));
        $list = [];
        if ($raw !== '' && $raw !== '*') {
            foreach (explode(',', $raw) as $part) {
                $origin = rtrim(trim($part), '/');
                if ($origin !== '') {
                    $list[] = $origin;
                }
            }
        }

        return array_values(array_unique($list));
    }

    public static function isLoopback(string $url): bool
    {
        $url = strtolower(trim($url));
        if ($url === '') {
            return true;
        }

        return str_contains($url, ':5155')
            || str_contains($url, '127.0.0.1')
            || str_contains($url, 'localhost')
            || str_contains($url, '0.0.0.0')
            || str_contains($url, '[::1]');
    }
}

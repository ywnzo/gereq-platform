<?php

declare(strict_types=1);

namespace Gereq\Support;

/**
 * Hard public path prefix for subdirectory deploys (e.g. /realityV3).
 *
 * APP_URL=https://host/realityV3/admin → prefix /realityV3
 * so generated /admin/login becomes /realityV3/admin/login.
 */
final class UrlPrefix
{
    private static ?string $prefix = null;

    public static function reset(): void
    {
        self::$prefix = null;
    }

    public static function get(): string
    {
        if (self::$prefix !== null) {
            return self::$prefix;
        }

        $explicit = trim((string) env_value('APP_URL_PREFIX', ''));
        if ($explicit !== '') {
            self::$prefix = '/' . trim($explicit, '/');
            if (self::$prefix === '/') {
                self::$prefix = '';
            }

            return self::$prefix;
        }

        $appUrl = rtrim((string) env_value('APP_URL', ''), '/');
        $path = (string) (parse_url($appUrl, PHP_URL_PATH) ?? '');
        $path = rtrim($path, '/');
        if ($path === '' || $path === '/') {
            self::$prefix = '';

            return self::$prefix;
        }

        // /realityV3/admin → /realityV3 ; /realityV3 → /realityV3
        if (str_ends_with($path, '/admin')) {
            $path = substr($path, 0, -strlen('/admin'));
        }

        self::$prefix = $path === '' || $path === '/' ? '' : $path;

        return self::$prefix;
    }

    /** Prefix a root-absolute app path (/admin, /build, /api, …). */
    public static function path(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            return $path;
        }
        // Already prefixed or external-ish
        $prefix = self::get();
        if ($prefix === '' || str_starts_with($path, $prefix . '/') || $path === $prefix) {
            return $path;
        }

        return $prefix . $path;
    }
}

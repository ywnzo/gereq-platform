<?php

declare(strict_types=1);

namespace Gereq\Support;

/**
 * Configurable admin URL path (default "admin").
 *
 * Code and modules keep the canonical "/admin/…" convention; rewrite() maps
 * those paths to the configured base (e.g. ADMIN_PATH=panel → /panel/…,
 * ADMIN_PATH=gereq-platform/admin → /gereq-platform/admin/…).
 */
final class AdminPath
{
    public const CANONICAL = 'admin';

    /** Reserved first-segment names that must not host the admin UI. */
    private const RESERVED = ['api', 'build', 'media', 'storage', 'design', 'brand', 'public', 'assets'];

    private static ?string $segment = null;

    public static function reset(): void
    {
        self::$segment = null;
    }

    /**
     * Configured path without leading slash (one or more segments),
     * e.g. "admin", "panel", or "gereq-platform/admin".
     */
    public static function segment(): string
    {
        if (self::$segment !== null) {
            return self::$segment;
        }

        // Read env directly (same pattern as UrlPrefix) so tests and late
        // overrides work; config/admin.php mirrors this value at boot.
        $raw = trim((string) env_value('ADMIN_PATH', self::CANONICAL), '/');
        self::$segment = self::sanitize($raw);

        return self::$segment;
    }

    /** Root-absolute admin base, e.g. "/admin" or "/gereq-platform/admin". */
    public static function base(): string
    {
        return '/' . self::segment();
    }

    /**
     * Build an admin path under the configured base.
     * Accepts "users", "/users", or canonical "/admin/users".
     */
    public static function path(string $suffix = ''): string
    {
        $suffix = trim($suffix);
        if ($suffix === '' || $suffix === '/') {
            return self::base();
        }

        if (!str_starts_with($suffix, '/')) {
            $suffix = '/' . $suffix;
        }

        return self::rewrite(str_starts_with($suffix, '/' . self::CANONICAL)
            ? $suffix
            : '/' . self::CANONICAL . $suffix);
    }

    /**
     * Map canonical "/admin…" paths to the configured base.
     * Non-admin paths are returned unchanged.
     */
    public static function rewrite(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            return $path;
        }

        $canonical = '/' . self::CANONICAL;
        $base = self::base();
        if ($base === $canonical) {
            return $path;
        }

        if ($path === $canonical || str_starts_with($path, $canonical . '/')) {
            return $base . substr($path, strlen($canonical));
        }

        return $path;
    }

    /** Whether $path is the admin base or a subpath (configured segment). */
    public static function matches(string $path): bool
    {
        $base = self::base();

        return $path === $base || str_starts_with($path, $base . '/');
    }

    /**
     * Whether $path is the canonical "/admin" segment (even when ADMIN_PATH is relocated).
     * Used to keep /admin out of storefront SPA fallback when the platform lives elsewhere.
     */
    public static function isCanonicalPath(string $path): bool
    {
        $canonical = '/' . self::CANONICAL;

        return $path === $canonical || str_starts_with($path, $canonical . '/');
    }

    public static function sanitize(string $raw): string
    {
        $raw = trim($raw, '/');
        if ($raw === '') {
            return self::CANONICAL;
        }

        $parts = explode('/', $raw);
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $part) !== 1) {
                return self::CANONICAL;
            }
        }

        $first = strtolower($parts[0]);
        if (in_array($first, self::RESERVED, true)) {
            return self::CANONICAL;
        }

        // Nested paths under the canonical segment are reserved for rewrite targets only.
        if (count($parts) > 1 && $first === self::CANONICAL) {
            return self::CANONICAL;
        }

        return implode('/', $parts);
    }
}

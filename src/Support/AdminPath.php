<?php

declare(strict_types=1);

namespace Gereq\Support;

/**
 * Configurable admin URL path segment (default "admin").
 *
 * Code and modules keep the canonical "/admin/…" convention; rewrite() maps
 * those paths to the configured base (e.g. ADMIN_PATH=panel → /panel/…).
 */
final class AdminPath
{
    public const CANONICAL = 'admin';

    private static ?string $segment = null;

    public static function reset(): void
    {
        self::$segment = null;
    }

    /**
     * Single path segment only (no slashes), e.g. "admin" or "panel".
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

    /** Root-absolute admin base, e.g. "/admin" or "/panel". */
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

    public static function sanitize(string $raw): string
    {
        $raw = trim($raw, '/');
        if ($raw === '' || str_contains($raw, '/') || preg_match('/^[a-zA-Z0-9_-]+$/', $raw) !== 1) {
            return self::CANONICAL;
        }

        $reserved = ['api', 'build', 'media', 'storage', 'design', 'brand', 'public'];
        if (in_array(strtolower($raw), $reserved, true)) {
            return self::CANONICAL;
        }

        return $raw;
    }
}

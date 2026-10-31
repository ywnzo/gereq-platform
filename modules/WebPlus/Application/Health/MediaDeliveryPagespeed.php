<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\Health;

/**
 * Pure helpers for media delivery / font budget health probes.
 */
final class MediaDeliveryPagespeed
{
    /** Soft budget for a self-hosted outlined Material Symbols woff2 (follow-up: subset/SVG). */
    public const OUTLINED_FONT_SOFT_BYTES = 200_000;

    public static function cacheControlIsImmutablePublic(?string $cacheControl): bool
    {
        if ($cacheControl === null || $cacheControl === '') {
            return false;
        }
        $cc = strtolower($cacheControl);

        return str_contains($cc, 'immutable')
            && (str_contains($cc, 'public') || preg_match('#max-age=\d+#', $cc) === 1);
    }

    /**
     * Largest material-symbols-outlined*.woff2 under storefront assets (bytes), or null if none.
     */
    public static function largestOutlinedFontBytes(string $storefrontRoot): ?int
    {
        $assets = rtrim($storefrontRoot, '/') . '/assets';
        if (!is_dir($assets)) {
            return null;
        }

        $max = null;
        $pattern = $assets . '/material-symbols-outlined*.woff2';
        foreach (glob($pattern) ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $size = filesize($file);
            if ($size === false) {
                continue;
            }
            $max = $max === null ? $size : max($max, $size);
        }

        return $max;
    }
}

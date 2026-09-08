<?php

declare(strict_types=1);

namespace Gereq\Support;

use Gereq\Foundation\Gereq;

/**
 * Platform semver — single source of truth is the root VERSION file.
 */
final class AppVersion
{
    public const DEFAULT = '0.1.0';

    private static ?string $cached = null;

    public static function current(?string $basePath = null): string
    {
        if ($basePath === null && self::$cached !== null) {
            return self::$cached;
        }

        $root = $basePath ?? (Gereq::has() ? Gereq::app()->basePath() : dirname(__DIR__, 2));
        $file = rtrim($root, '/') . '/VERSION';
        $version = self::DEFAULT;

        if (is_file($file)) {
            $raw = self::normalize((string) file_get_contents($file));
            if ($raw !== '' && self::isSemver($raw)) {
                $version = $raw;
            }
        }

        if ($basePath === null) {
            self::$cached = $version;
        }

        return $version;
    }

    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }

    /** True when $candidate is strictly newer than the running platform (or $than). */
    public static function isNewerThan(string $candidate, ?string $than = null): bool
    {
        return self::compare($candidate, $than ?? self::current()) > 0;
    }

    public static function normalize(string $tagOrVersion): string
    {
        $v = trim($tagOrVersion);
        if (str_starts_with(strtolower($v), 'v') && preg_match('/^v\d/i', $v) === 1) {
            $v = substr($v, 1);
        }

        return $v;
    }

    public static function isSemver(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', self::normalize($version)) === 1;
    }

    /** @internal test / CLI bootstrap */
    public static function clearCache(): void
    {
        self::$cached = null;
    }
}

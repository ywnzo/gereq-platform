<?php

declare(strict_types=1);

namespace Gereq\Module;

/**
 * Semver helpers for modules — patch/minor/major with component rollover at 100.
 */
final class ModuleSemver
{
    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function parse(string $version): ?array
    {
        $v = trim($version);
        if (str_starts_with(strtolower($v), 'v') && preg_match('/^v\d/i', $v) === 1) {
            $v = substr($v, 1);
        }
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $v, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    public static function bumpPatch(string $version): string
    {
        $parts = self::parse($version);
        if ($parts === null) {
            return '0.0.1';
        }
        [$major, $minor, $patch] = $parts;
        ++$patch;
        if ($patch >= 100) {
            $patch = 0;
            ++$minor;
        }
        if ($minor >= 100) {
            $minor = 0;
            ++$major;
            $patch = 0;
        }

        return $major . '.' . $minor . '.' . $patch;
    }
}

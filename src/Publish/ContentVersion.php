<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;

/**
 * Content publication version (separate from platform VERSION / AppVersion).
 * Format: MAJOR.MINOR.PATCH[letter] e.g. 0.1.0, 0.1.0a, 0.1.1
 */
final class ContentVersion
{
    public const SETTING_KEY = 'app.content_version';
    public const DEFAULT = '0.1.0';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function current(): string
    {
        $raw = (string) $this->settings->getLive(self::SETTING_KEY, self::DEFAULT);
        $normalized = self::normalize($raw);

        return $normalized !== '' ? $normalized : self::DEFAULT;
    }

    public function bump(int $changeCount): string
    {
        $next = self::next($this->current(), $changeCount);
        $this->settings->setLive(self::SETTING_KEY, $next, SettingRecord::TYPE_STRING);

        return $next;
    }

    public static function next(string $current, int $changeCount): string
    {
        $parsed = self::parse($current);
        if ($changeCount > 5) {
            return sprintf('%d.%d.%d', $parsed['major'], $parsed['minor'], $parsed['patch'] + 1);
        }

        $letter = $parsed['letter'];
        if ($letter === null) {
            return sprintf('%d.%d.%d%s', $parsed['major'], $parsed['minor'], $parsed['patch'], 'a');
        }

        if ($letter === 'z') {
            return sprintf('%d.%d.%d%s', $parsed['major'], $parsed['minor'], $parsed['patch'] + 1, 'a');
        }

        return sprintf(
            '%d.%d.%d%s',
            $parsed['major'],
            $parsed['minor'],
            $parsed['patch'],
            chr(ord($letter) + 1),
        );
    }

    public static function normalize(string $version): string
    {
        $v = trim($version);
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)([a-z])?$/i', $v, $m) !== 1) {
            return '';
        }

        $letter = isset($m[4]) && $m[4] !== '' ? strtolower($m[4]) : '';

        return $m[1] . '.' . $m[2] . '.' . $m[3] . $letter;
    }

    public static function isValid(string $version): bool
    {
        return self::normalize($version) !== '';
    }

    /**
     * @return array{major: int, minor: int, patch: int, letter: ?string}
     */
    public static function parse(string $version): array
    {
        $normalized = self::normalize($version);
        if ($normalized === '') {
            $normalized = self::DEFAULT;
        }

        preg_match('/^(\d+)\.(\d+)\.(\d+)([a-z])?$/', $normalized, $m);

        return [
            'major' => (int) $m[1],
            'minor' => (int) $m[2],
            'patch' => (int) $m[3],
            'letter' => isset($m[4]) && $m[4] !== '' ? $m[4] : null,
        ];
    }
}

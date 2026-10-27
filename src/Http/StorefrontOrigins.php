<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Foundation\Gereq;
use Gereq\Setting\Settings;

/** Effective storefront origins, shared by public security and preview consumers. */
final class StorefrontOrigins
{
    public const SETTING = 'storefront.origins_override';
    public const UNAVAILABLE = '!storefront-origins-unavailable!';

    public static function raw(): string
    {
        $fallback = trim((string) env_value('FRONTEND_ORIGIN', ''));
        if (!Gereq::has()) {
            return $fallback;
        }

        try {
            return self::resolve(Gereq::app()->make(Settings::class), $fallback);
        } catch (\Throwable) {
            return self::UNAVAILABLE;
        }
    }

    public static function resolve(Settings $settings, string $fallback): string
    {
        if (!$settings->storageAvailable()) {
            return self::UNAVAILABLE;
        }
        $override = trim((string) $settings->getLive(self::SETTING, ''));

        return $override !== '' ? $override : trim($fallback);
    }

    /** @return list<string> */
    public static function list(): array
    {
        $raw = self::raw();
        if ($raw === '' || $raw === '*' || $raw === self::UNAVAILABLE) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => rtrim(trim($value), '/'),
            explode(',', $raw),
        ), static fn (string $value): bool => $value !== '')));
    }

    /** @return list<string>|null */
    public static function parseOverride(string $value): ?array
    {
        $items = preg_split('/[\s,]+/', trim($value)) ?: [];
        $result = [];
        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }
            $parts = parse_url($item);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
                || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
                || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '/')
                || filter_var(rtrim($item, '/'), FILTER_VALIDATE_URL) === false) {
                return null;
            }
            $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
            if (isset($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            $result[] = $origin;
        }

        return $result === [] ? null : array_values(array_unique($result));
    }
}

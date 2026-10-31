<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

/**
 * Reduces a raw user agent string to `device_type` / `browser` / `os`.
 *
 * The raw UA is never stored (README "Pseudonymity rules"); only these three coarse buckets are.
 */
final class UserAgent
{
    public const DEVICE_MOBILE = 'mobile';

    public const DEVICE_TABLET = 'tablet';

    public const DEVICE_DESKTOP = 'desktop';

    public const DEVICE_BOT = 'bot';

    public const DEVICE_UNKNOWN = 'unknown';

    /**
     * @return array{device_type: string, browser: string, os: string}
     */
    public function describe(?string $userAgent): array
    {
        $ua = mb_strtolower(trim((string) $userAgent));

        return [
            'device_type' => $this->deviceType($ua),
            'browser' => $this->browser($ua),
            'os' => $this->os($ua),
        ];
    }

    public function deviceType(?string $userAgent): string
    {
        $ua = mb_strtolower(trim((string) $userAgent));
        if ($ua === '') {
            return self::DEVICE_UNKNOWN;
        }
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet') || (str_contains($ua, 'android') && !str_contains($ua, 'mobile'))) {
            return self::DEVICE_TABLET;
        }
        if (str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'ipod')) {
            return self::DEVICE_MOBILE;
        }

        return self::DEVICE_DESKTOP;
    }

    public function browser(?string $userAgent): string
    {
        $ua = mb_strtolower(trim((string) $userAgent));

        return match (true) {
            $ua === '' => 'unknown',
            str_contains($ua, 'edg/') || str_contains($ua, 'edga/') || str_contains($ua, 'edgios/') => 'edge',
            str_contains($ua, 'opr/') || str_contains($ua, 'opera') => 'opera',
            str_contains($ua, 'samsungbrowser') => 'samsung',
            str_contains($ua, 'firefox') || str_contains($ua, 'fxios/') => 'firefox',
            str_contains($ua, 'chrome') || str_contains($ua, 'crios/') => 'chrome',
            str_contains($ua, 'safari') => 'safari',
            default => 'other',
        };
    }

    public function os(?string $userAgent): string
    {
        $ua = mb_strtolower(trim((string) $userAgent));

        return match (true) {
            $ua === '' => 'unknown',
            str_contains($ua, 'windows') => 'windows',
            str_contains($ua, 'android') => 'android',
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod') => 'ios',
            str_contains($ua, 'mac os x') || str_contains($ua, 'macintosh') => 'macos',
            str_contains($ua, 'cros') => 'chromeos',
            str_contains($ua, 'linux') => 'linux',
            default => 'other',
        };
    }
}

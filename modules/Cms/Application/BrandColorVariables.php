<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Gereq\Brand\Branding;
use Gereq\Brand\PaletteResolver;
use Gereq\Setting\Settings;

final class BrandColorVariables
{
    /** @return array<string, string> */
    public static function resolve(Settings $settings): array
    {
        $signal = (string) $settings->get('brand.color_signal', '#d7ff45');
        $ink = (string) $settings->get('brand.color_ink', '#0a0b0a');
        $cream = (string) $settings->get('brand.color_cream', '#f5f5ef');
        $palette = self::palette($settings);
        $primary = $palette['colors']['primary'];

        $values = [
            // Existing content can continue using these legacy tokens.
            'brand.signal' => $signal,
            'brand.ink' => $ink,
            'brand.cream' => $cream,
            'brand.primary' => $primary,
        ];
        foreach (['secondary', 'accent', 'light_background', 'light_surface', 'light_header', 'dark_background', 'dark_surface', 'dark_header'] as $key) {
            $values['brand.' . $key] = $palette['colors'][$key];
        }
        $mode = $palette['modes'][$palette['storefront_mode']];
        $values['brand.background'] = $mode['background'];
        $values['brand.surface'] = $mode['surface'];
        $values['brand.header'] = $mode['header'];
        $values['brand.text'] = $mode['text'];
        $values['brand.muted'] = $mode['text_muted'];

        return $values;
    }

    /** @return array{version: int, storefront_mode: string, colors: array<string, string>, modes: array<string, array<string, string>>, warnings: list<string>} */
    public static function palette(Settings $settings): array
    {
        $primary = (string) $settings->get('brand.site_primary', '#17375a');
        $ink = (string) $settings->get('brand.color_ink', '#0a0b0a');
        $cream = (string) $settings->get('brand.color_cream', '#f5f5ef');
        $palette = (new Branding($settings))->resolvedPalette();
        if ($palette === null) {
            $fallbackPrimary = self::hex($primary, '#17375a');
            $fallbackInk = self::hex($ink, '#0a0b0a');
            $palette = (new PaletteResolver())->resolve([
                'primary' => $fallbackPrimary,
                'secondary' => $fallbackPrimary,
                'accent' => $fallbackPrimary,
                'light_background' => self::hex($cream, '#f5f5ef'),
                'light_surface' => self::hex($cream, '#f5f5ef'),
                'dark_background' => $fallbackInk,
                'dark_surface' => $fallbackInk,
                'storefront_mode' => self::isDark($fallbackInk) ? 'dark' : 'light',
            ]);
        }
        return $palette;
    }

    public static function saveStorefrontMode(Settings $settings, string $mode): void
    {
        $stored = $settings->get('brand.palette', []);
        $palette = is_array($stored) && $stored !== [] ? $stored : self::palette($settings)['colors'];
        $palette['storefront_mode'] = $mode;
        $settings->set('brand.palette', $palette, \Gereq\Setting\SettingRecord::TYPE_JSON, 'core');
    }

    private static function hex(string $value, string $fallback): string
    {
        if (preg_match('/^#([a-f0-9]{3})$/i', $value) === 1) {
            return '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
        }

        return preg_match('/^#[a-f0-9]{6}$/i', $value) === 1 ? $value : $fallback;
    }

    private static function isDark(string $color): bool
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $channel = hexdec(substr($color, $offset, 2)) / 255;
            $channels[] = $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2] < 0.45;
    }
}

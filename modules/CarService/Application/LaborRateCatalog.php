<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

/**
 * Hodinové sazby práce v nastavení servisu (výchozí + per tým).
 */
final class LaborRateCatalog
{
    public const SETTING_DEFAULT = 'carservice.labor_rate_default';
    public const SETTING_BODY_SHOP = 'carservice.labor_rate_body_shop';
    public const SETTING_PAINT_SHOP = 'carservice.labor_rate_paint_shop';

    public const DEFAULT_VALUE = '650';
    public const BODY_SHOP_VALUE = '650';
    public const PAINT_SHOP_VALUE = '750';

    /** @return list<array{team: string|null, setting: string, field: string, label: string, default: string}> */
    public static function definitions(): array
    {
        return [
            [
                'team' => null,
                'setting' => self::SETTING_DEFAULT,
                'field' => 'labor_rate_default',
                'label' => 'carservice.settings.labor_rate_default',
                'default' => self::DEFAULT_VALUE,
            ],
            [
                'team' => 'body_shop',
                'setting' => self::SETTING_BODY_SHOP,
                'field' => 'labor_rate_body_shop',
                'label' => 'carservice.settings.labor_rate_body_shop',
                'default' => self::BODY_SHOP_VALUE,
            ],
            [
                'team' => 'paint_shop',
                'setting' => self::SETTING_PAINT_SHOP,
                'field' => 'labor_rate_paint_shop',
                'label' => 'carservice.settings.labor_rate_paint_shop',
                'default' => self::PAINT_SHOP_VALUE,
            ],
        ];
    }

    /** @return list<string> */
    public static function settingKeys(): array
    {
        return array_column(self::definitions(), 'setting');
    }

    public static function settingForTeam(?string $team): string
    {
        return match ($team) {
            'body_shop' => self::SETTING_BODY_SHOP,
            'paint_shop' => self::SETTING_PAINT_SHOP,
            default => self::SETTING_DEFAULT,
        };
    }

    /**
     * @param callable(string, string): string $get settings getter (key, default) => value
     */
    public static function resolve(callable $get, ?string $team = null): float
    {
        $default = (float) $get(self::SETTING_DEFAULT, self::DEFAULT_VALUE);
        if ($team === null || $team === '') {
            return $default;
        }
        $key = self::settingForTeam($team);
        if ($key === self::SETTING_DEFAULT) {
            return $default;
        }
        $raw = trim((string) $get($key, ''));
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }

        return (float) $raw;
    }
}

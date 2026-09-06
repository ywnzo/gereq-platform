<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps Jama category URL slugs (often without diacritics) to i18n labels + icons.
 */
final class CategoryTaxonomy
{
    /**
     * slug-normalized key => [translation suffix, icon key]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const MAP = [
        // Top-level groups (from Jama browse)
        'motor' => ['motor', 'pc-engine'],
        'filtr' => ['filter', 'pc-filter'],
        'filtry' => ['filter', 'pc-filter'],
        'remeny' => ['belts', 'pc-belt'],
        'remen' => ['belts', 'pc-belt'],
        'prevodovka' => ['transmission', 'pc-gearbox'],
        'spojka-prislusenstvi' => ['clutch_accessories', 'pc-clutch'],
        'spojka' => ['clutch', 'pc-clutch'],
        'pohon-kol' => ['wheel_drive', 'pc-drive'],
        'brzdovy-system' => ['brake_system', 'pc-brake'],
        'brzdy' => ['brake_system', 'pc-brake'],
        'rizeni' => ['steering', 'pc-steering'],
        'odpruzeni-tlumeni' => ['suspension', 'pc-suspension'],
        'odpruzeni' => ['suspension', 'pc-suspension'],
        'zaveseni-napravy-vedeni-kol' => ['axle_suspension', 'pc-axle'],
        'zaveseni-napravy' => ['axle_suspension', 'pc-axle'],
        'vyfukovy-system' => ['exhaust', 'pc-exhaust'],
        'vyfuk' => ['exhaust', 'pc-exhaust'],
        'chlazeni' => ['cooling', 'pc-cooling'],
        'topeni-klimatizace' => ['hvac', 'pc-hvac'],
        'topeni' => ['hvac', 'pc-hvac'],
        'klimatizace' => ['hvac', 'pc-hvac'],
        'elektrika' => ['electrics', 'pc-electric'],
        'elektricke-prislusenstvi' => ['electrics', 'pc-electric'],
        'osvetleni' => ['lighting', 'pc-light'],
        'karoserie' => ['body', 'pc-body'],
        'skla' => ['glass', 'pc-glass'],
        'stierace' => ['wipers', 'pc-wiper'],
        'sterace' => ['wipers', 'pc-wiper'],
        'palivovy-system' => ['fuel_system', 'pc-fuel'],
        'palivo' => ['fuel_system', 'pc-fuel'],
        'prislusenstvi' => ['accessories', 'pc-parts'],
        'udrzba' => ['maintenance', 'pc-parts'],
        'podvozek' => ['chassis', 'pc-axle'],
        'hnaci-ustraji' => ['drivetrain', 'pc-drive'],
        'hnaci-ustri' => ['drivetrain', 'pc-drive'],

        // Common subgroups
        'hydraulicky-filtr' => ['filter_hydraulic', 'pc-filter'],
        'kabinovy-filtr' => ['filter_cabin', 'pc-filter'],
        'olejovy-filtr' => ['filter_oil', 'pc-filter'],
        'palivovy-filtr' => ['filter_fuel', 'pc-filter'],
        'vzduchovy-filtr' => ['filter_air', 'pc-filter'],
        'automaticka-prevodovka' => ['transmission_auto', 'pc-gearbox'],
        'manualni-prevodovka' => ['transmission_manual', 'pc-gearbox'],
        'privod-vzduchu' => ['air_intake', 'pc-cooling'],
        'preplnovani' => ['forced_induction', 'pc-engine'],
        'mazani' => ['lubrication', 'pc-engine'],
        'rozvody' => ['timing', 'pc-belt'],
        'klikova-skrin' => ['crankcase', 'pc-engine'],
        'hlava-valcu' => ['cylinder_head', 'pc-engine'],
        'vstrikovani' => ['injection', 'pc-fuel'],
        'zapalovani' => ['ignition', 'pc-electric'],
        'startovani' => ['starting', 'pc-electric'],
        'alternator' => ['alternator', 'pc-electric'],
        'akumulator' => ['battery', 'pc-electric'],
        'tlumic' => ['shock_absorber', 'pc-suspension'],
        'pruzina' => ['spring', 'pc-suspension'],
        'stabilizator' => ['stabilizer', 'pc-suspension'],
        'loziska' => ['bearings', 'pc-parts'],
        'tesneni' => ['seals', 'pc-parts'],
        'olej' => ['oil', 'pc-filter'],
        'kotouc' => ['brake_disc', 'pc-brake'],
        'desticky' => ['brake_pads', 'pc-brake'],
        'brzdove-desticky' => ['brake_pads', 'pc-brake'],
        'brzdovy-kotouc' => ['brake_disc', 'pc-brake'],
        'brzdovy-bubny' => ['brake_drum', 'pc-brake'],
        'hadice' => ['hoses', 'pc-parts'],
        'cerpadlo' => ['pump', 'pc-parts'],
        'senzor' => ['sensor', 'pc-electric'],
        'ridici-jednotka' => ['ecu', 'pc-electric'],
    ];

    /**
     * @return array{label: string, icon: string, key: ?string}
     */
    public static function resolve(TranslatorInterface $translator, string $pathOrSlug, string $fallbackName = ''): array
    {
        $slug = self::leafSlug($pathOrSlug);
        $normalized = self::normalizeKey($slug);
        $entry = self::MAP[$normalized] ?? null;

        if ($entry === null) {
            // Try without trailing plural/noise
            $alt = rtrim($normalized, 'y');
            $entry = self::MAP[$alt] ?? null;
        }

        if ($entry !== null) {
            $key = 'partscatalog.category.' . $entry[0];
            $label = $translator->trans($key);
            if ($label === $key) {
                $label = $fallbackName !== '' ? $fallbackName : self::titleCase($slug);
            }

            return ['label' => $label, 'icon' => $entry[1], 'key' => $key];
        }

        return [
            'label' => $fallbackName !== '' ? $fallbackName : self::titleCase($slug),
            'icon' => 'pc-parts',
            'key' => null,
        ];
    }

    public static function leafSlug(string $pathOrSlug): string
    {
        $path = trim(str_replace('\\', '/', $pathOrSlug), '/');
        if ($path === '') {
            return '';
        }
        $parts = explode('/', $path);

        return (string) end($parts);
    }

    public static function normalizeKey(string $slug): string
    {
        $s = mb_strtolower(trim(rawurldecode($slug)));
        $s = strtr($s, [
            'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
            'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ö' => 'o', 'ř' => 'r', 'š' => 's',
            'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z',
        ]);
        $s = str_replace(['_', ' '], '-', $s);
        $s = preg_replace('/[^a-z0-9\-]+/', '', $s) ?? $s;
        $s = preg_replace('/-+/', '-', $s) ?? $s;

        return trim($s, '-');
    }

    private static function titleCase(string $slug): string
    {
        $s = str_replace(['-', '_'], ' ', rawurldecode($slug));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return mb_convert_case(trim($s), MB_CASE_TITLE, 'UTF-8');
    }
}

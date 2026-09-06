<?php

declare(strict_types=1);

namespace Modules\BikeService\Application\Catalog;

/**
 * Servisní taxonomie dílů — skupiny + typické značky komponent (dílna).
 */
final class PartsTaxonomy
{
    /**
     * @return list<array{
     *   id: string,
     *   label: string,
     *   icon: string,
     *   query: string,
     *   brands: list<string>,
     *   parts: list<string>
     * }>
     */
    public static function groups(): array
    {
        return [
            [
                'id' => 'drivetrain',
                'label' => 'bikeservice.catalog.group.drivetrain',
                'icon' => 'settings',
                'query' => 'přehazovačka',
                'brands' => ['Shimano', 'SRAM', 'Campagnolo', 'Rotor', 'Praxis', 'Gates'],
                'parts' => ['Přehazovačka', 'Přesmykač', 'Kazeta', 'Řetěz', 'Kliky', 'Převodníky', 'Středové složení', 'Řadicí páky'],
            ],
            [
                'id' => 'brakes',
                'label' => 'bikeservice.catalog.group.brakes',
                'icon' => 'build',
                'query' => 'brzdové destičky',
                'brands' => ['Shimano', 'SRAM', 'Magura', 'Hope', 'TRP', 'Tektro', 'Formula'],
                'parts' => ['Brzdové třmeny', 'Brzdové páky', 'Kotouče', 'Destičky', 'Bowdeny / hadice', 'V-brzdy'],
            ],
            [
                'id' => 'wheels',
                'label' => 'bikeservice.catalog.group.wheels',
                'icon' => 'refresh',
                'query' => 'plášť',
                'brands' => ['Mavic', 'DT Swiss', 'Fulcrum', 'Shimano', 'Zipp', 'Hunt', 'Wally'],
                'parts' => ['Ráfky', 'Náboje', 'Výplet', 'Pláště', 'Duše', 'Bezdušové pásky', 'Ventilky'],
            ],
            [
                'id' => 'frame_fork',
                'label' => 'bikeservice.catalog.group.frame_fork',
                'icon' => 'layers',
                'query' => 'vidlice',
                'brands' => ['RockShox', 'Fox', 'SR Suntour', 'Manitou', 'Cane Creek', 'Chris King'],
                'parts' => ['Vidlice', 'Tlumič', 'Hlavové složení', 'Podložky', 'Představec'],
            ],
            [
                'id' => 'cockpit',
                'label' => 'bikeservice.catalog.group.cockpit',
                'icon' => 'filters',
                'query' => 'sedlo',
                'brands' => ['Race Face', 'Thomson', 'Ritchey', 'Pro', 'Bontrager', 'Ergon'],
                'parts' => ['Řídítka', 'Gripy / omotávka', 'Sedlovka', 'Sedlo', 'Košík'],
            ],
            [
                'id' => 'ebike',
                'label' => 'bikeservice.catalog.group.ebike',
                'icon' => 'zap',
                'query' => 'baterie elektrokolo',
                'brands' => ['Bosch', 'Shimano Steps', 'Brose', 'Yamaha', 'Bafang', 'Mahle', 'TQ'],
                'parts' => ['Motor', 'Baterie', 'Display / remote', 'Nabíječka', 'Kabeláž', 'Senzory'],
            ],
            [
                'id' => 'pedals',
                'label' => 'bikeservice.catalog.group.pedals',
                'icon' => 'build',
                'query' => 'pedály',
                'brands' => ['Shimano', 'Look', 'Crankbrothers', 'Time', 'HT', 'Wellgo'],
                'parts' => ['Nášlapné pedály', 'Platformové pedály', 'Kufry', 'Remínky'],
            ],
            [
                'id' => 'accessories',
                'label' => 'bikeservice.catalog.group.accessories',
                'icon' => 'package',
                'query' => 'blatníky',
                'brands' => ['Topeak', 'Ortlieb', 'Lezyne', 'Cateye', 'Garmin', 'Knog'],
                'parts' => ['Blatníky', 'Nosič', 'Světla', 'Zámek', 'Pumpa', 'Brašny'],
            ],
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::groups() as $group) {
            if ($group['id'] === $id) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Unikátní značky komponent napříč taxonomií (sidebar filtr).
     *
     * @return list<string>
     */
    public static function allBrands(): array
    {
        $seen = [];
        $out = [];
        foreach (self::groups() as $group) {
            foreach ($group['brands'] as $brand) {
                $key = mb_strtolower($brand);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $brand;
            }
        }
        sort($out, SORT_STRING | SORT_FLAG_CASE);

        return $out;
    }

    public static function defaultQuery(string $id): string
    {
        $group = self::find($id);

        return is_array($group) ? (string) ($group['query'] ?? '') : '';
    }

    /**
     * Slova pro filtrování tržních nabídek v dané kategorii (aspoň jedno musí sedět).
     *
     * @return list<string>
     */
    public static function marketTokens(string $id): array
    {
        return match ($id) {
            'drivetrain' => ['prehazov', 'presmyk', 'kazet', 'cassette', 'retez', 'chain', 'klik', 'prevodnik', 'stredov', 'radic', 'pastork', 'pohon'],
            'brakes' => ['brzd', 'kotouc', 'destick', 'rotor', 'brake', 'pad', 'trmen', 'hadice', 'bowden'],
            'wheels' => ['plast', 'duse', 'rafek', 'naboj', 'vyplet', 'tire', 'tyre', 'tube', 'ventil', 'bezdus', 'kolo'],
            'frame_fork' => ['vidlic', 'tlumic', 'hlavov', 'predstavec', 'fork', 'shock', 'headset'],
            'cockpit' => ['sedl', 'riditk', 'grip', 'sedlovk', 'kosik', 'saddle', 'handlebar', 'stem'],
            'ebike' => ['bater', 'motor', 'elektrokolo', 'ebike', 'display', 'nabijec', 'bosch', 'steps', 'bafang'],
            'pedals' => ['pedal', 'kufr', 'naslap'],
            'accessories' => ['blatnik', 'nosic', 'svetla', 'zamek', 'pump', 'brasn', 'light', 'lock', 'fender'],
            default => [],
        };
    }

    /** Map Bike Index English group → taxonomy id */
    public static function mapBikeDataGroup(string $group): string
    {
        return match ($group) {
            'Drivetrain' => 'drivetrain',
            'Brakes' => 'brakes',
            'Wheels' => 'wheels',
            'Frame and Fork' => 'frame_fork',
            'Additional Parts' => 'accessories',
            'Cargo' => 'accessories',
            default => 'accessories',
        };
    }
}

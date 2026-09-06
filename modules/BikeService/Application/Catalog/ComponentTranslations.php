<?php

declare(strict_types=1);

namespace Modules\BikeService\Application\Catalog;

/**
 * České názvy a synonyma pro Bike Index component_types / specs.
 */
final class ComponentTranslations
{
    /** @return array<string, string> EN group → CS */
    public static function groups(): array
    {
        return [
            'Drivetrain' => 'Pohon',
            'Brakes' => 'Brzdy',
            'Wheels' => 'Kola a pláště',
            'Frame and Fork' => 'Rám a vidlice',
            'Additional Parts' => 'Doplňky',
            'Cargo' => 'Náklad / brašny',
        ];
    }

    /** @return array<string, string> EN component name → CS */
    public static function components(): array
    {
        return [
            'Aero Bars/Extensions/Bar Ends' => 'Aero nástavce / barendy',
            'Axle Nuts' => 'Matice nápravy',
            'Bashguard/Chain Guide' => 'Bashguard / vodítko řetězu',
            'Basket' => 'Košík',
            'Bell/Noisemaker' => 'Zvonek',
            'Bottom Bracket' => 'Středové složení',
            'Brake' => 'Brzda',
            'Brake Cable' => 'Brzdové lanko / hadice',
            'Brake Lever' => 'Brzdová páka',
            'Brake Pad' => 'Brzdové destičky / špalky',
            'Brake Rotor' => 'Brzdový kotouč',
            'Cargo Bike Side Rails' => 'Boční madla cargo kola',
            'Chain' => 'Řetěz',
            'Chain Tensioners' => 'Napínák řetězu',
            'Chainrings' => 'Převodníky',
            'Child Seat' => 'Dětská sedačka',
            'Cog/Cassette/Freewheel' => 'Kazeta / pastorek / volnoběžka',
            'Computer' => 'Cyklocomputer',
            'Crankset' => 'Kliky',
            'Derailleur' => 'Přehazovačka / přesmykač',
            'Detangler' => 'Detangler (rotor)',
            'Drive Belt' => 'Hnací řemen',
            'E-vehicle Battery' => 'Baterie elektrokola',
            'E-vehicle Charger' => 'Nabíječka elektrokola',
            'E-vehicle Display/Remote' => 'Display / ovladač e-kola',
            'E-vehicle Motor' => 'Motor elektrokola',
            'E-vehicle Wiring Harness' => 'Kabeláž elektrokola',
            'Fairing' => 'Kapotáž / kapota',
            'Fender' => 'Blatník',
            'Fork' => 'Vidlice',
            'Frame Bag' => 'Brašna do rámu',
            'Generator/Dynamo' => 'Dynamo',
            'Grips/Tape' => 'Gripy / omotávka',
            'Handlebar' => 'Řídítka',
            'Handlebar Bag' => 'Brašna na řídítka',
            'Headset' => 'Hlavové složení',
            'Hub' => 'Náboj',
            'Hub Guard' => 'Hub guard',
            'Internal Frame Storage' => 'Úložný prostor v rámu',
            'Kickstand' => 'Stojánek',
            'Lights' => 'Světla',
            'Pannier' => 'Boční brašna',
            'Pedals' => 'Pedály',
            'Pegs' => 'Pegs (stupátka)',
            'Rack' => 'Nosič',
            'Rear Suspension' => 'Zadní tlumič',
            'Rim' => 'Ráfek',
            'Saddle' => 'Sedlo',
            'Seat Bag' => 'Brašna pod sedlo',
            'Seatpost' => 'Sedlovka',
            'Seatpost Clamp' => 'Objímka sedlovky',
            'Shift and Brake Lever' => 'Kombinovaná řadicí/brzdová páka',
            'Shift Cable' => 'Řadicí lanko / bowden',
            'Shifter' => 'Řadicí páka',
            'Spokes' => 'Dráty',
            'Stem' => 'Představec',
            'Throttle' => 'Plynová páčka',
            'Tire' => 'Plášť',
            'Toe Clips' => 'Kliče / remínky',
            'Top of Rack Bag' => 'Brašna na nosič',
            'Top Tube Bag' => 'Brašna na horní rámovou trubku',
            'Training Wheels' => 'Přídavná kolečka',
            'Tube' => 'Duše',
            'unknown' => 'Neznámé',
            'Water Bottle Cage' => 'Košík na láhev',
            'Wheel' => 'Kolo (sestava)',
        ];
    }

    /** @return array<string, string> EN brake type → CS */
    public static function brakes(): array
    {
        return [
            'Band Brake' => 'Pásová brzda',
            'Cable Actuated Hydraulic' => 'Hybridní kotoučová (lanko + hydraulika)',
            'Caliper' => 'Čelisťová brzda',
            'Cantilever' => 'Cantilever',
            'Center Pull' => 'Center-pull čelist',
            'Coaster Brake' => 'Torpédo (zadní nášlapná)',
            'Delta Brake' => 'Delta brzda',
            'Disc Hydraulic' => 'Kotoučová hydraulická',
            'Disc Mechanical' => 'Kotoučová mechanická',
            'Drum Brake' => 'Bubnová brzda',
            'Hydraulic Rim' => 'Hydraulická ráfková',
            'Linear Pull' => 'V-brzda',
            'Rod Brake' => 'Tyčová brzda',
            'Roller Brake' => 'Roller brake',
            'Side Pull' => 'Side-pull čelist',
            'Spoon Brake' => 'Lžícová brzda',
            'U-Brake' => 'U-brzda',
        ];
    }

    /** @return array<string, string> EN handlebar → CS */
    public static function handlebars(): array
    {
        return [
            'BMX Style' => 'BMX řídítka',
            'Drop Bars' => 'Silniční (drop)',
            'Flat or Riser' => 'Rovná / riser',
            'Forward Facing' => 'Dopředu skloněná',
            'Not Handlebars' => 'Jiné',
            'Rear Facing' => 'Dozadu skloněná',
        ];
    }

    /**
     * Synonyma hledání (CZ / slang) → EN component names.
     *
     * @return array<string, list<string>>
     */
    public static function synonyms(): array
    {
        return [
            'přehazovačka' => ['Derailleur'],
            'prehazovacka' => ['Derailleur'],
            'přesmyk' => ['Derailleur'],
            'presmyk' => ['Derailleur'],
            'přesmykač' => ['Derailleur'],
            'presmykac' => ['Derailleur'],
            'kazeta' => ['Cog/Cassette/Freewheel'],
            'pastorek' => ['Cog/Cassette/Freewheel'],
            'volnoběžka' => ['Cog/Cassette/Freewheel'],
            'řetěz' => ['Chain'],
            'retez' => ['Chain'],
            'kliky' => ['Crankset'],
            'převodník' => ['Chainrings'],
            'prevodnik' => ['Chainrings'],
            'střed' => ['Bottom Bracket'],
            'stred' => ['Bottom Bracket'],
            'středové' => ['Bottom Bracket'],
            'řazení' => ['Shifter', 'Shift and Brake Lever', 'Shift Cable'],
            'razeni' => ['Shifter', 'Shift and Brake Lever'],
            'brzda' => ['Brake', 'Brake Pad', 'Brake Lever', 'Brake Rotor', 'Brake Cable'],
            'brzdy' => ['Brake', 'Brake Pad', 'Brake Lever', 'Brake Rotor'],
            'destičky' => ['Brake Pad'],
            'desticky' => ['Brake Pad'],
            'špalky' => ['Brake Pad'],
            'kotouč' => ['Brake Rotor'],
            'kotouc' => ['Brake Rotor'],
            'plášť' => ['Tire'],
            'plast' => ['Tire'],
            'duše' => ['Tube'],
            'duse' => ['Tube'],
            'ráfek' => ['Rim'],
            'rafek' => ['Rim'],
            'náboj' => ['Hub'],
            'naboj' => ['Hub'],
            'vidlice' => ['Fork'],
            'tlumič' => ['Rear Suspension', 'Fork'],
            'tlumic' => ['Rear Suspension', 'Fork'],
            'sedlo' => ['Saddle'],
            'sedlovka' => ['Seatpost'],
            'řídítka' => ['Handlebar'],
            'riditka' => ['Handlebar'],
            'představec' => ['Stem'],
            'predstavec' => ['Stem'],
            'gripy' => ['Grips/Tape'],
            'omotávka' => ['Grips/Tape'],
            'pedály' => ['Pedals'],
            'pedaly' => ['Pedals'],
            'baterie' => ['E-vehicle Battery'],
            'motor' => ['E-vehicle Motor'],
            'nabíječka' => ['E-vehicle Charger'],
            'nabijecka' => ['E-vehicle Charger'],
            'display' => ['E-vehicle Display/Remote'],
            'blatník' => ['Fender'],
            'blatnik' => ['Fender'],
            'nosič' => ['Rack'],
            'nosic' => ['Rack'],
            'světla' => ['Lights'],
            'svetla' => ['Lights'],
            'zámek' => [],
            'bowden' => ['Brake Cable', 'Shift Cable'],
        ];
    }

    public static function component(string $english): string
    {
        return self::components()[$english] ?? $english;
    }

    public static function group(string $english): string
    {
        return self::groups()[$english] ?? $english;
    }

    public static function brake(string $english): string
    {
        return self::brakes()[$english] ?? $english;
    }

    public static function handlebar(string $english): string
    {
        return self::handlebars()[$english] ?? $english;
    }

    /** @return list<string> English component names matched by Czech query */
    public static function resolveQueryToEnglishNames(string $query): array
    {
        $q = self::normalize($query);
        if ($q === '') {
            return [];
        }
        $hits = [];
        foreach (self::synonyms() as $syn => $names) {
            $synN = self::normalize(is_string($syn) ? $syn : (string) $syn);
            if ($synN === '' || (!str_contains($q, $synN) && !str_contains($synN, $q))) {
                continue;
            }
            foreach ((array) $names as $name) {
                if (is_string($name) && $name !== '') {
                    $hits[$name] = true;
                }
            }
        }
        foreach (self::components() as $en => $cs) {
            if (str_contains(self::normalize($cs), $q) || str_contains(self::normalize($en), $q)) {
                $hits[$en] = true;
            }
        }

        return array_keys($hits);
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $map = [
            'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ĺ' => 'l',
            'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't',
            'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z',
        ];

        return strtr($value, $map);
    }
}

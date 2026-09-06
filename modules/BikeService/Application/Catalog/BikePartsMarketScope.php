<?php

declare(strict_types=1);

namespace Modules\BikeService\Application\Catalog;

/**
 * Omezuje tržní hledání dílů na kola / elektrokola (dotaz + filtr výsledků).
 */
final class BikePartsMarketScope
{
    /**
     * České (a běžně v CZ používané) cyklo e-shopy / značky.
     * Matchuje source id i název obchodu ve výsledcích srovnávačů.
     *
     * @var list<string>
     */
    private const BIKE_ONLY_SOURCES = [
        // aktivní scrapery / source id
        'mojekolo',
        // české e-shopy
        'koloshop',
        'kupkolo',
        'sportega',
        'bikemax',
        'bike-max',
        'prokolo',
        'ekolo',
        'kola-online',
        'kolaonline',
        'bike-arena',
        'bikearena',
        'bike-store',
        'bikestore',
        'bikeplus',
        'bike-plus',
        'bikeonline',
        'bike-online',
        'kolostar',
        'kolokram',
        'kolo-nakup',
        'kolonakup',
        'maxbike',
        'max-bike',
        'freebike',
        'free-bike',
        'velosport',
        'cycloshop',
        'cykloshop',
        'cyklocentrum',
        'cyklocomp',
        'cyklospecialista',
        'worldofbike',
        'world-of-bike',
        'bike-forum',
        'bikeforum',
        'bikefun',
        'bike-fun',
        'elektrokola',
        'e-kola',
        'ekola',
        'lovelec',
        'leaderfox',
        'leader fox',
        'leader-fox',
        '4ever',
        '4 ever',
        'author',
        'apache',
        'crussis',
        'dema',
        'superior',
        'kellys',
        'festka',
        'duratec',
        'repete',
        'remerx',
        'rockmachine',
        'rock machine',
        'force bike',
        'max1',
        'kovys',
        'kolacap',
        'kola cap',
        'cepelak',
        'bike gallery',
        'bikegallery',
        'rosebikes',
        'rose bikes',
        'mtbiker',
    ];

    /** Seznam Zboží: sportovní kola / díly (root / parent). */
    private const SEZNAM_BIKE_ROOT = 461;
    private const SEZNAM_BIKE_PARENT = 471;

    /**
     * Dotaz pro srovnávače (Heureka, Seznam, Hledejceny) — vždy cyklo kontext.
     */
    public static function marketplaceQuery(string $query): string
    {
        $q = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
        if ($q === '') {
            return 'cyklistické díly';
        }
        $n = ComponentTranslations::normalize($q);
        if (self::hasBikeContext($n)) {
            return $q;
        }

        // Ambiguous CZ terms → force bike sense
        $map = [
            'kazeta' => 'kazeta kolo',
            'retez' => 'řetěz kolo',
            'kotouc' => 'brzdový kotouč kolo',
            'brzdovy kotouc' => 'brzdový kotouč kolo',
            'desticky' => 'brzdové destičky kolo',
            'brzdove desticky' => 'brzdové destičky kolo',
            'baterie' => 'baterie elektrokolo',
            'motor' => 'motor elektrokolo',
            'sedlo' => 'sedlo kolo',
            'duse' => 'duše kolo',
            'plast' => 'plášť kolo',
            'vidlice' => 'vidlice kolo',
            'klik' => 'kliky kolo',
            'kliky' => 'kliky kolo',
            'pastorek' => 'pastorek kazeta kolo',
            'prehazovacka' => 'přehazovačka kolo',
            'presmykac' => 'přesmykač kolo',
            'pedaly' => 'pedály kolo',
            'pedal' => 'pedály kolo',
            'grip' => 'gripy kolo',
            'gripy' => 'gripy kolo',
            'predstavec' => 'představec kolo',
            'riditka' => 'řídítka kolo',
            'naboj' => 'náboj kolo',
        ];
        if (isset($map[$n])) {
            return $map[$n];
        }
        foreach ($map as $needle => $scoped) {
            if (str_starts_with($n, $needle . ' ')) {
                $words = preg_split('/\s+/u', $q) ?: [];
                array_shift($words);

                return trim($scoped . ($words !== [] ? ' ' . implode(' ', $words) : ''));
            }
        }

        return $q . ' kolo';
    }

    /**
     * Dotaz pro cyklo e-shopy — bez zbytečného „kolo“ (už jsou v kontextu).
     */
    public static function bikeShopQuery(string $query): string
    {
        $q = trim($query);

        return $q !== '' ? $q : 'díly';
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function accepts(array $item, string $source = ''): bool
    {
        $source = $source !== '' ? $source : (string) ($item['source'] ?? '');
        $name = (string) ($item['name'] ?? '');
        $desc = (string) ($item['description'] ?? '');
        $brand = (string) ($item['brand'] ?? '');
        $params = '';
        if (isset($item['parameters']) && is_array($item['parameters'])) {
            $params = implode(' ', array_map(static fn ($p): string => (string) $p, $item['parameters']));
        }
        $hay = ComponentTranslations::normalize($name . ' ' . $desc . ' ' . $brand . ' ' . $params);

        if (self::isDenied($hay)) {
            return false;
        }

        // Veřejný Heureka scrape už jde přes cyklo dotaz (+ kolo) — nefiltruj pryč
        if ($source === 'heureka') {
            return $name !== '';
        }

        if (self::isBikeOnlyShop($source, (string) ($item['shop'] ?? ''))) {
            return $name !== '';
        }

        // Seznam Zboží: kategorie sportovní kola / díly
        $root = isset($item['category_root']) ? (int) $item['category_root'] : 0;
        $parent = isset($item['category_parent']) ? (int) $item['category_parent'] : 0;
        if ($source === 'seznam' && ($root === self::SEZNAM_BIKE_ROOT || $parent === self::SEZNAM_BIKE_PARENT)) {
            return $name !== '';
        }

        if (self::hasBikeSignal($hay, $brand)) {
            return true;
        }

        // Obecné srovnávače bez cyklo signálu = mimo téma
        return false;
    }

    private static function isBikeOnlyShop(string $source, string $shop): bool
    {
        $sourceN = ComponentTranslations::normalize($source);
        $shopN = ComponentTranslations::normalize($shop);
        foreach (self::BIKE_ONLY_SOURCES as $needle) {
            $n = ComponentTranslations::normalize($needle);
            if ($n === '' || mb_strlen($n) < 4) {
                continue;
            }
            if ($sourceN === $n || str_contains($sourceN, $n) || str_contains($shopN, $n)) {
                return true;
            }
        }

        return false;
    }

    private static function hasBikeContext(string $normalizedQuery): bool
    {
        foreach ([
            'kolo', 'kola', 'kolob', 'cyklo', 'cykl', 'bike', 'mtb', 'gravel', 'silnic',
            'elektrokolo', 'ebike', 'e-bike', 'shimano', 'sram', 'campagnolo', 'bosch',
            'brose', 'yamaha', 'bafang', 'prehazov', 'presmyk', 'pastorek', 'kazeta kolo',
            'retez kolo', 'rockshox', 'fox', 'schwalbe', 'continental', 'maxxis',
        ] as $token) {
            if (str_contains($normalizedQuery, $token)) {
                return true;
            }
        }

        return false;
    }

    private static function isDenied(string $hay): bool
    {
        foreach ([
            'inkoust', 'toner', 'tiskarn', 'cartridge', 'pixma', 'deskjet', 'officejet',
            'laserjet', 'epson', 'xerox', 'kyocera',
            'originálni inkoust', 'originalni inkoust', 'inkoustova kazeta', 'tonerova kazeta',
            'vhs', 'audiokazeta', 'audio kazeta', 'filmova kazeta', 'kapesnik',
            'naramek', 'nahrdelnik', 'retezek stribr', 'retezek zlat',
            'baterie aa', 'baterie aaa', 'tužková', 'tuzkova',
            'motorovy olej', 'motorový olej', 'automobil',
        ] as $bad) {
            if (str_contains($hay, ComponentTranslations::normalize($bad))) {
                return true;
            }
        }
        // HP ink pattern: "HP 305" + kazeta without bike words
        if (preg_match('/\bhp\s*\d/u', $hay) === 1 && str_contains($hay, 'kazet') && !self::hasBikeSignal($hay, '')) {
            return true;
        }

        return false;
    }

    private static function hasBikeSignal(string $hay, string $brand): bool
    {
        $brandN = ComponentTranslations::normalize($brand);
        foreach ([
            'shimano', 'sram', 'campagnolo', 'rockshox', 'fox', 'magura', 'hope', 'tektro',
            'trp', 'schwalbe', 'continental', 'maxxis', 'mavic', 'dt swiss', 'fulcrum',
            'zipp', 'bosch', 'brose', 'bafang', 'yamaha', 'mahle', 'tq', 'specialized',
            'trek', 'giant', 'canyon', 'cube', 'author', 'apache', 'kelly', 'kellys', 'crussis',
            'dema', 'superior', '4ever', 'leader fox', 'lovelec', 'festka', 'duratec', 'repete',
            'rockmachine', 'deore', 'ultegra', 'dura ace', 'dura-ace', 'xtr', 'slx', 'alivio', 'acera',
            'eagle', 'gx ', 'xx1', 'nx ', 'force', 'rival', 'red axs', 's-ride', 'sride',
            'ice toolz', 'icetoolz', 'kovys', 'campa', 'max1', 'remerx',
        ] as $b) {
            if ($brandN === ComponentTranslations::normalize($b) || str_contains($hay, ComponentTranslations::normalize($b))) {
                return true;
            }
        }

        foreach ([
            'kolo', 'kolobez', 'cyklo', 'bike', 'mtb', 'gravel', 'silnicni', 'elektrokolo',
            'ebike', 'e bike', 'prehazovacka', 'presmykac', 'pastorek', 'kazeta',
            'retez', 'klik', 'prevodnik', 'brzdove destick', 'brzdovy kotouc',
            'plast 27', 'plast 29', 'plast 28', 'duse', 'vidlice', 'sedlovka',
            'riditka', 'predstavec', 'pedal', 'naboj', 'zapleten', 'karbonovy ram',
            'speed', ' rychlost', 'zub', 'sgs', 'shadow',
        ] as $token) {
            $t = ComponentTranslations::normalize($token);
            if ($t !== '' && str_contains($hay, $t)) {
                // "kazeta" alone is weak — require another signal unless paired with speeds/teeth
                if ($t === 'kazeta') {
                    if (preg_match('/\b\d+\s*s(?:p|peed)?\b/u', $hay)
                        || preg_match('/\d+\s*-\s*\d+\s*z/u', $hay)
                        || str_contains($hay, 'pastork')
                        || str_contains($hay, 'zub')
                        || str_contains($hay, 'shimano')
                        || str_contains($hay, 'sram')
                        || str_contains($hay, 'campa')
                        || str_contains($hay, 'kolo')
                        || str_contains($hay, 'stahov')
                        || str_contains($hay, 'voditk')) {
                        return true;
                    }
                    continue;
                }
                if ($t === 'retez' && (str_contains($hay, 'stribr') || str_contains($hay, 'zlat') || str_contains($hay, 'sperk'))) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }
}

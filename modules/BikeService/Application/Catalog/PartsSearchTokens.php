<?php

declare(strict_types=1);

namespace Modules\BikeService\Application\Catalog;

/**
 * Tokenizace dotazu na díly — partial match (kazeta/kazety → „kazet“, čísla, značky).
 */
final class PartsSearchTokens
{
    /** @var array<string, string> */
    private const TYPOS = [
        'sminano' => 'shimano',
        'shimamo' => 'shimano',
        'shiamno' => 'shimano',
        'shramano' => 'shimano',
        'shrimoano' => 'shimano',
        'conti' => 'continental',
        'schwalbee' => 'schwalbe',
        'rock shox' => 'rockshox',
        'rock-shox' => 'rockshox',
    ];

    /** @var list<string> */
    private const STOP = [
        'kolo', 'kola', 'na', 'pro', 'do', 'z', 'ze', 'a', 'i', 'the', 'of', 'and',
        'cyklo', 'bike', 'dil', 'dily', 'soucastka', 'soucastky',
    ];

    /**
     * Opraví překlepy ve frázi (např. sminano → shimano).
     */
    public static function correctQuery(string $query): string
    {
        $q = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
        if ($q === '') {
            return '';
        }
        $parts = preg_split('/(\s+)/u', $q, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$q];
        $out = '';
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^\s+$/u', $part) === 1) {
                $out .= $part;
                continue;
            }
            $n = ComponentTranslations::normalize($part);
            $out .= self::TYPOS[$n] ?? $part;
        }

        return trim($out);
    }

    /**
     * @return list<string> stemy tokenů (bez stopwords), unikátní, řazené
     */
    public static function tokenize(string $query): array
    {
        $corrected = self::correctQuery($query);
        $n = ComponentTranslations::normalize($corrected);
        if ($n === '') {
            return [];
        }
        $parts = preg_split('/[\s,;\/+|]+/u', $n) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $part = self::TYPOS[$part] ?? $part;
            $stem = self::stem($part);
            if ($stem === '' || mb_strlen($stem) < 2) {
                continue;
            }
            if (in_array($stem, self::STOP, true)) {
                continue;
            }
            $tokens[$stem] = $stem;
        }
        $list = array_values($tokens);
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * Cache / srovnání klíč z tokenů (pořadí slov v dotazu nehraje roli).
     */
    public static function cacheFingerprint(string $query): string
    {
        $tokens = self::tokenize($query);

        return $tokens === [] ? ComponentTranslations::normalize(self::correctQuery($query)) : implode('|', $tokens);
    }

    /**
     * @param list<string> $tokens
     * @param array<string, mixed> $item
     */
    public static function itemMatches(array $item, array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }
        $hay = self::itemHaystack($item);

        return self::hayMatches($hay, $tokens);
    }

    /**
     * Aspoň jeden token musí sedět (pro taxonomii kategorie).
     *
     * @param list<string> $tokens
     * @param array<string, mixed> $item
     */
    public static function itemMatchesAny(array $item, array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }
        $hay = self::itemHaystack($item);
        foreach ($tokens as $token) {
            if ($token !== '' && self::tokenInHay($hay, $token)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $item */
    private static function itemHaystack(array $item): string
    {
        $params = '';
        if (isset($item['parameters']) && is_array($item['parameters'])) {
            $params = implode(' ', array_map(static fn ($p): string => (string) $p, $item['parameters']));
        }

        return ComponentTranslations::normalize(
            (string) ($item['name'] ?? '') . ' '
            . (string) ($item['brand'] ?? '') . ' '
            . (string) ($item['description'] ?? '') . ' '
            . (string) ($item['shop'] ?? '') . ' '
            . $params
        );
    }

    /**
     * @param list<string> $tokens
     */
    public static function hayMatches(string $normalizedHay, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (!self::tokenInHay($normalizedHay, $token)) {
                return false;
            }
        }

        return true;
    }

    private static function tokenInHay(string $hay, string $token): bool
    {
        foreach (self::expandToken($token) as $alt) {
            if ($alt !== '' && str_contains($hay, $alt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function expandToken(string $token): array
    {
        $map = [
            'kazet' => ['kazet', 'cassette', 'pastork', 'freewheel', 'volnobezn'],
            'retez' => ['retez', 'chain'],
            'plast' => ['plast', 'tire', 'tyre'],
            'dus' => ['dus', 'tube', 'duse'],
            'prehazovac' => ['prehazovac', 'derailleur', 'rd '],
            'presmyk' => ['presmyk', 'front derailleur', 'fd '],
            'brzd' => ['brzd', 'brake'],
            'kotouc' => ['kotouc', 'rotor', 'disc'],
            'destick' => ['destick', 'pad', 'pads'],
            'sedl' => ['sedl', 'saddle'],
            'vidlic' => ['vidlic', 'fork'],
            'pedal' => ['pedal'],
            'bateri' => ['bateri', 'battery', 'akumulator'],
            'motor' => ['motor', 'drive unit'],
        ];

        return $map[$token] ?? [$token];
    }

    public static function stem(string $token): string
    {
        $t = ComponentTranslations::normalize($token);
        if ($t === '' || preg_match('/^\d+[a-z]?$/u', $t) === 1) {
            return $t;
        }
        // značky / cizí názvy nesternuj
        foreach ([
            'shimano', 'sram', 'campagnolo', 'schwalbe', 'continental', 'maxxis', 'rockshox',
            'magura', 'tektro', 'bosch', 'brose', 'bafang', 'yamaha', 'specialized', 'mavic',
        ] as $brand) {
            if ($t === $brand || str_starts_with($brand, $t) && mb_strlen($t) >= 4) {
                return $brand === $t ? $t : $t;
            }
        }
        if (isset(self::TYPOS[$t])) {
            return self::TYPOS[$t];
        }
        foreach (['ovymi', 'ovych', 'ovyma', 'ami', 'emi', 'imi', 'ove', 'ovy', 'ova', 'ych', 'ymi', 'ama', 'ata'] as $suf) {
            if (mb_strlen($t) > mb_strlen($suf) + 3 && str_ends_with($t, $suf)) {
                return mb_substr($t, 0, -mb_strlen($suf));
            }
        }
        // české tvary: kazeta/kazety, pláště → stem
        if (mb_strlen($t) >= 5 && (str_ends_with($t, 'y') || str_ends_with($t, 'a') || str_ends_with($t, 'e'))) {
            return mb_substr($t, 0, -1);
        }

        return $t;
    }
}

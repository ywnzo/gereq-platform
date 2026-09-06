<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

/**
 * Společné položky hustého protokolu příjmu (PDF + interaktivní formulář).
 */
final class IntakeProtocolCatalog
{
    public const AXLE_KEYS = ['brakes', 'pins', 'shocks', 'boots', 'tires'];

    /** @return list<string> */
    public static function equipment(): array
    {
        return [
            'Radio / multimedia',
            'Anténa',
            'Lékárnička',
            'Výstražný trojúhelník',
            'Tažné lano',
            'Zvedák',
            'Rezervní kolo / sada',
            'Hasicí přístroj',
            'Doklady vozidla',
            'Klíče / dálkové ovládání',
        ];
    }

    /** @return list<string> */
    public static function services(): array
    {
        return [
            'Odvoz zákazníka',
            'Dovoz / odvoz vozu',
            'Mytí vozidla',
            'Náhradní vozidlo',
        ];
    }

    /** @return list<string> */
    public static function inspectionItems(): array
    {
        return [
            'Kapalina ostřikovačů',
            'Brzdová kapalina',
            'Chladicí kapalina',
            'Olej / filtr',
            'Baterie',
            'Těsnost motoru',
            'Stěrače / sklo',
            'Světla / signalizace',
            'Hadice / řemeny',
        ];
    }

    /** @return list<string> */
    public static function bodyItems(): array
    {
        return [
            'Pedály / ruční brzda',
            'Kontrolky palubní desky',
            'Odlétající kamínky',
            'Škrábance',
            'Prohnutí / důlky',
            'Jiná poškození',
        ];
    }

    /**
     * @return array<int, string> zone number => short label
     */
    public static function zones(): array
    {
        return [
            1 => 'kapota',
            2 => 'střecha',
            3 => 'kufr',
            4 => 'LP blat.',
            5 => 'PP blat.',
            6 => 'LZ blat.',
            7 => 'PZ blat.',
            8 => 'L dveře',
            9 => 'P dveře',
            10 => 'náraz. P',
            11 => 'náraz. Z',
            12 => 'spod.',
        ];
    }

    /**
     * @return array<string, string> key => label
     */
    public static function axleItems(): array
    {
        return [
            'brakes' => 'Brzdy',
            'pins' => 'Čepy',
            'shocks' => 'Tlumiče',
            'boots' => 'Manžety',
            'tires' => 'Pneu',
        ];
    }

    /**
     * Normalizuje POST / uložená data.
     *
     * @param array<string, mixed> $raw
     * @return array{
     *   equipment: list<string>,
     *   services: list<string>,
     *   inspection: array<string, string>,
     *   body: array<string, string>,
     *   zones: list<int>,
     *   axles: array{front: array<string, string>, rear: array<string, string>}
     * }
     */
    public static function normalize(array $raw): array
    {
        return [
            'equipment' => self::normalizeCheckedList($raw['equipment'] ?? [], self::equipment()),
            'services' => self::normalizeCheckedList($raw['services'] ?? [], self::services()),
            'inspection' => self::normalizeMarkMap($raw['inspection'] ?? [], self::inspectionItems()),
            'body' => self::normalizeMarkMap($raw['body'] ?? [], self::bodyItems()),
            'zones' => self::normalizeZones($raw['zones'] ?? []),
            'axles' => [
                'front' => self::normalizeAxleSide($raw['axles']['front'] ?? $raw['axles_front'] ?? []),
                'rear' => self::normalizeAxleSide($raw['axles']['rear'] ?? $raw['axles_rear'] ?? []),
            ],
        ];
    }

    /**
     * Prázdný protokol (vše nezaškrtnuto).
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return self::normalize([]);
    }

    /**
     * @param mixed $raw
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function normalizeCheckedList(mixed $raw, array $allowed): array
    {
        $allowedFlip = array_flip($allowed);
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $key => $value) {
            // checkbox array: name="equipment[]" value="Radio"
            if (is_int($key) || ctype_digit((string) $key)) {
                $label = (string) $value;
                if (isset($allowedFlip[$label])) {
                    $out[] = $label;
                }
                continue;
            }
            // map: equipment[Radio]=1
            $label = (string) $key;
            if (isset($allowedFlip[$label]) && self::isTruthy($value)) {
                $out[] = $label;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param mixed $raw
     * @param list<string> $allowed
     * @return array<string, string> label => ok|todo
     */
    private static function normalizeMarkMap(mixed $raw, array $allowed): array
    {
        $allowedFlip = array_flip($allowed);
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $label => $mark) {
            $label = (string) $label;
            if (!isset($allowedFlip[$label])) {
                continue;
            }
            $mark = (string) $mark;
            if ($mark === 'ok' || $mark === 'todo') {
                $out[$label] = $mark;
            }
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function normalizeZones(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_int($key) || ctype_digit((string) $key)) {
                $n = (int) $value;
            } else {
                $n = (int) $key;
                if (!self::isTruthy($value)) {
                    continue;
                }
            }
            if ($n >= 1 && $n <= 12) {
                $out[] = $n;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<string, string>
     */
    private static function normalizeAxleSide(mixed $raw): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach (self::AXLE_KEYS as $key) {
            $mark = (string) ($raw[$key] ?? '');
            if ($mark === 'ok' || $mark === 'todo') {
                $out[$key] = $mark;
            }
        }

        return $out;
    }

    private static function isTruthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v !== 0.0;
        }
        $s = strtolower(trim((string) $v));

        return $s !== '' && $s !== '0' && $s !== 'false' && $s !== 'off' && $s !== 'no';
    }
}

<?php

declare(strict_types=1);

namespace Modules\Fleet\Application;

use Gereq\Setting\Settings;

/**
 * Klient DATAOVOZIDLECH.cz (ORV / malý TP) — API klíč jen ze settings, nikdy hardcoded.
 */
final class OrvClient
{
    private const ENDPOINT = 'https://api.dataovozidlech.cz/api/vehicletechnicaldata/v2';

    public function __construct(private readonly Settings $settings) {}

    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * @return array{ok: bool, code: int, data: ?array<string, mixed>, error: ?string}
     */
    public function lookup(string $orvId): array
    {
        $orvId = trim($orvId);
        if ($orvId === '') {
            return ['ok' => false, 'code' => 0, 'data' => null, 'error' => 'fleet.error.orv_required'];
        }
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return ['ok' => false, 'code' => 0, 'data' => null, 'error' => 'fleet.error.orv_not_configured'];
        }

        $url = self::ENDPOINT . '?orv=' . rawurlencode($orvId);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'code' => 0, 'data' => null, 'error' => 'fleet.error.orv_request_failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['api_key: ' . $apiKey],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'code' => $code, 'data' => null, 'error' => $curlError !== '' ? $curlError : 'fleet.error.orv_request_failed'];
        }
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'code' => $code, 'data' => null, 'error' => 'fleet.error.orv_invalid_response'];
        }
        if ($code !== 200) {
            $status = $decoded['Status'] ?? $decoded['status'] ?? 'HTTP ' . $code;

            return ['ok' => false, 'code' => $code, 'data' => null, 'error' => is_string($status) ? $status : 'fleet.error.orv_request_failed'];
        }
        $data = $decoded['Data'] ?? $decoded['data'] ?? null;
        if (!is_array($data)) {
            return ['ok' => false, 'code' => $code, 'data' => null, 'error' => 'fleet.error.orv_empty'];
        }

        return ['ok' => true, 'code' => $code, 'data' => $data, 'error' => null];
    }

    /**
     * Mapuje odpověď API na pole vozidla (plate/vin/brand/…).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function mapToVehicleFields(array $data): array
    {
        $out = [];
        $vin = $this->pick($data, ['VIN', 'vin', 'Vin']);
        if ($vin !== null) {
            $out['vin'] = strtoupper((string) $vin);
        }
        // SPZ v odpovědi ORV často chybí — API vrací technická data, ne registrační značku.
        $plate = $this->pick($data, ['SPZ', 'spz', 'PlateNumber', 'plateNumber', 'RZ', 'RegZnacka', 'RegistracniZnacka']);
        if ($plate !== null) {
            $out['plate'] = (string) $plate;
        }
        $brand = $this->pick($data, ['TovarniZnacka', 'tovarniZnacka', 'Brand', 'brand', 'Make']);
        if ($brand !== null) {
            $out['brand'] = (string) $brand;
        }
        $model = $this->pick($data, ['ObchodniOznaceni', 'obchodniOznaceni', 'Model', 'model']);
        if ($model === null) {
            $typ = $this->pick($data, ['Typ', 'typ']);
            if ($typ !== null) {
                $model = $this->modelFromTyp((string) $typ, $brand !== null ? (string) $brand : null);
            }
        }
        if ($model !== null) {
            $out['model'] = (string) $model;
        }
        $year = $this->pickYear($data);
        if ($year !== null) {
            $out['model_year'] = $year;
        }
        $generation = VehicleGeneration::resolve(
            isset($out['brand']) ? (string) $out['brand'] : null,
            isset($out['model']) ? (string) $out['model'] : null,
            $year,
        );
        if ($generation !== null) {
            $out['generation'] = $generation;
        }
        $color = $this->pick($data, ['VozidloKaroserieBarva', 'vozidloKaroserieBarva', 'Barva', 'barva', 'Color', 'color']);
        if ($color !== null) {
            $out['color'] = (string) $color;
        }
        $fuel = $this->pick($data, ['Palivo', 'palivo', 'Fuel', 'fuel']);
        if ($fuel !== null) {
            $out['fuel'] = $this->normalizeFuel((string) $fuel);
        } else {
            $fromFlags = $this->fuelFromFlags($data);
            if ($fromFlags !== null) {
                $out['fuel'] = $fromFlags;
            }
        }
        $gearbox = $this->resolveGearbox($data);
        if ($gearbox !== null) {
            $out['gearbox'] = $gearbox;
        }
        $motor = $this->buildMotorization($data);
        if ($motor !== null) {
            $out['motorization'] = $motor;
        }
        $stk = $this->pickDate($data, [
            'PravidelnaTechnickaProhlidkaDo',
            'pravidelnaTechnickaProhlidkaDo',
            'STK',
            'stk',
            'StkDo',
        ]);
        if ($stk !== null) {
            $out['stk_at'] = $stk;
        }

        // Extra ORV tech fields (form + tech_json)
        $kind = $this->pick($data, ['VozidloDruh', 'vozidloDruh']);
        if ($kind !== null) {
            $out['vehicle_kind'] = (string) $kind;
        }
        $body = $this->pick($data, ['VozidloDruh2', 'vozidloDruh2', 'KaroserieDruh', 'karoserieDruh']);
        if ($body !== null) {
            $out['body_type'] = (string) $body;
        }
        $cat = $this->pick($data, ['Kategorie', 'kategorie']);
        if ($cat !== null) {
            $out['category'] = (string) $cat;
        }
        $emis = $this->pick($data, ['EmisniUroven', 'emisniUroven']);
        if ($emis !== null) {
            $out['emission'] = (string) $emis;
        }
        $power = $this->parsePowerKw($this->pick($data, ['MotorMaxVykon', 'motorMaxVykon']));
        if ($power !== null) {
            $out['power_kw'] = $power;
        }
        $cc = $this->pick($data, ['MotorZdvihObjem', 'motorZdvihObjem']);
        if ($cc !== null && is_numeric((string) $cc)) {
            $out['displacement_cc'] = (int) $cc;
        }
        $weight = $this->pick($data, ['HmotnostiProvozni', 'hmotnostiProvozni']);
        if ($weight !== null && is_numeric((string) $weight)) {
            $out['weight_kg'] = (int) $weight;
        }
        $seats = $this->parseSeats($this->pick($data, ['VozidloKaroserieMist', 'vozidloKaroserieMist']));
        if ($seats !== null) {
            $out['seats'] = $seats;
        }
        $tp = $this->pick($data, ['CisloTp', 'cisloTp']);
        if ($tp !== null) {
            $out['tp_number'] = (string) $tp;
        }
        $firstReg = $this->pickDate($data, ['DatumPrvniRegistrace', 'DatumPrvniRegistraceVCr']);
        if ($firstReg !== null) {
            $out['first_registered_at'] = $firstReg;
        }
        $tires = $this->parseTireSize($this->pick($data, ['NapravyPneuRafky', 'napravyPneuRafky']));
        if ($tires !== null) {
            $out['tire_size'] = $tires;
        }
        $cons = $this->pick($data, ['SpotrebaNa100Km', 'spotrebaNa100Km']);
        if ($cons !== null) {
            $out['consumption'] = (string) $cons;
        }
        $vmax = $this->pick($data, ['NejvyssiRychlost', 'nejvyssiRychlost']);
        if ($vmax !== null && is_numeric((string) $vmax)) {
            $out['max_speed'] = (int) $vmax;
        }
        $homo = $this->pick($data, ['HomologaceEs', 'homologaceEs']);
        if ($homo !== null) {
            $out['homologation'] = (string) $homo;
        }
        $engine = $this->pick($data, ['MotorTyp', 'motorTyp']);
        if ($engine !== null) {
            $out['engine_code'] = (string) $engine;
        }
        $ver = $this->pick($data, ['Verze', 'verze']);
        if ($ver !== null) {
            $out['version_code'] = (string) $ver;
        }
        $notes = $this->pick($data, ['DalsiZaznamy', 'dalsiZaznamy']);
        if ($notes !== null) {
            $normalized = self::formatOrvNotes((string) $notes);
            if ($normalized !== '') {
                $out['orv_notes'] = $normalized;
            }
        }

        return $out;
    }

    /**
     * Pipe-separated ORV DalsiZaznamy → čitelný text (odřádkovaný).
     */
    public static function formatOrvNotes(string $raw): string
    {
        return implode("\n", self::formatOrvNotesLines($raw));
    }

    /**
     * @return list<string>
     */
    public static function formatOrvNotesLines(string $raw): array
    {
        $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        if ($raw === '') {
            return [];
        }

        // Už převedené (bez pipe) — jen vyčistit prázdné řádky.
        if (!str_contains($raw, '|')) {
            $lines = [];
            foreach (preg_split('/\n+/', $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }

            return $lines;
        }

        $parts = preg_split('/\|+/', $raw) ?: [];
        $out = [];
        $tires = [];
        $inTires = false;
        $flushTires = static function () use (&$out, &$tires, &$inTires): void {
            if ($tires === []) {
                $inTires = false;

                return;
            }
            $out[] = 'Povolené disky a pneumatiky:';
            foreach ($tires as $tire) {
                $out[] = '• ' . $tire;
            }
            $tires = [];
            $inTires = false;
        };

        foreach ($parts as $part) {
            $line = trim(preg_replace('/\s+/u', ' ', $part) ?? $part);
            $line = rtrim($line, " \t,;");
            if ($line === '') {
                continue;
            }

            if (preg_match('/^#20(?:-21)?\s*:?\s*(.*)$/iu', $line, $m) === 1) {
                $inTires = true;
                $rest = trim($m[1] ?? '');
                if ($rest !== '') {
                    $tires[] = self::formatTireLine($rest);
                }
                continue;
            }

            if ($inTires && self::looksLikeTireLine($line)) {
                $tires[] = self::formatTireLine($line);
                continue;
            }
            if ($inTires || $tires !== []) {
                $flushTires();
            }

            if (preg_match('/^ROZCHOD\s*:\s*(.+)$/iu', $line, $m) === 1) {
                $track = trim(str_replace('/', ' / ', $m[1]));
                $track = preg_replace('/\s+/', ' ', $track) ?? $track;
                $out[] = 'Rozchod: ' . $track;
                continue;
            }

            if (preg_match('/^#D2\s+úplné znění verze\s*:\s*(.+)$/iu', $line, $m) === 1
                || preg_match('/^#D2\s*:?\s*(.+)$/iu', $line, $m) === 1) {
                $out[] = 'Úplné znění verze: ' . rtrim(trim($m[1]), ',');
                continue;
            }

            if (preg_match('/^#12\s*:\s*max\s*(.+)$/iu', $line, $m) === 1) {
                $out[] = 'Max. délka: ' . trim($m[1]) . ' mm';
                continue;
            }

            if (preg_match('/^#14\s*:\s*min\s*(.+)$/iu', $line, $m) === 1) {
                $out[] = 'Min. výška: ' . trim($m[1]) . ' mm';
                continue;
            }

            if (preg_match('/^Variabilní provedení vozidla\s*:?\s*$/iu', $line) === 1) {
                $out[] = 'Variabilní provedení vozidla';
                continue;
            }

            if (preg_match('/^DNE\s+(\d{1,2})\.(\d{1,2})\.(\d{4})\s+BYL VYSTAVEN DRUHOPIS TP ZA POPSAN[YÝ]\.?$/iu', $line, $m) === 1) {
                $out[] = sprintf(
                    'Dne %02d. %02d. %s byl vystaven druhopis technického průkazu za popsaný.',
                    (int) $m[1],
                    (int) $m[2],
                    $m[3],
                );
                continue;
            }

            $out[] = $line;
        }

        $flushTires();

        return $out;
    }

    private static function looksLikeTireLine(string $line): bool
    {
        return preg_match('/^\d+(?:\.\d+)?J\s*x\s*\d+/iu', $line) === 1
            || preg_match('/\d+\/\d+\s*R\d+/iu', $line) === 1;
    }

    private static function formatTireLine(string $line): string
    {
        $line = trim($line);
        // "6J x 15 ET47 ; 195/65 R15 91H" → "6J × 15 ET47 · 195/65 R15 91H"
        $line = str_replace([' x ', ' X '], ' × ', $line);
        $line = preg_replace('/\s*;\s*/', ' · ', $line) ?? $line;
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        return rtrim($line, ' .');
    }

    private function apiKey(): string
    {
        return trim((string) $this->settings->get('fleet.orv_api_key', ''));
    }

    /** @param list<string> $keys */
    private function pick(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function pickDate(array $data, array $keys): ?string
    {
        $raw = $this->pick($data, $keys);
        if ($raw === null) {
            return null;
        }
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m) === 1) {
            return $m[1];
        }
        try {
            return (new \DateTimeImmutable($s))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function pickYear(array $data): ?int
    {
        $year = $this->pick($data, ['RokVyroby', 'rokVyroby', 'Year', 'year', 'manufacturedYear']);
        if ($year !== null && is_numeric((string) $year)) {
            $y = (int) $year;
            if ($y >= 1900 && $y <= 2100) {
                return $y;
            }
        }
        foreach (['DatumPrvniRegistrace', 'DatumPrvniRegistraceVCr', 'datumPrvniRegistrace'] as $key) {
            $raw = $data[$key] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            if (preg_match('/^(\d{4})/', $raw, $m) === 1) {
                $y = (int) $m[1];
                if ($y >= 1900 && $y <= 2100) {
                    return $y;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function buildMotorization(array $data): ?string
    {
        $parts = [];
        $typ = $this->pick($data, ['MotorTyp', 'motorTyp', 'Motor', 'motor', 'Motorization']);
        if ($typ !== null) {
            $parts[] = trim((string) $typ);
        }
        $cc = $this->pick($data, ['MotorZdvihObjem', 'motorZdvihObjem', 'ZdvihovyObjem']);
        if ($cc !== null && is_numeric((string) $cc)) {
            $parts[] = ((int) $cc) . ' cm³';
        }
        $power = $this->formatMotorPower($this->pick($data, ['MotorMaxVykon', 'motorMaxVykon']));
        if ($power !== null) {
            $parts[] = $power;
        }
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function formatMotorPower(mixed $raw): ?string
    {
        if ($raw === null || (string) $raw === '') {
            return null;
        }
        $s = trim((string) $raw);
        // API: "66 / 4800" = 66 kW při 4800 ot./min
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*\/\s*(\d+)/', $s, $m) === 1) {
            return str_replace(',', '.', $m[1]) . ' kW @ ' . $m[2] . ' ot.';
        }
        if (is_numeric($s)) {
            return $s . ' kW';
        }

        return $s;
    }

    private function modelFromTyp(string $typ, ?string $brand): string
    {
        $typ = trim($typ);
        if ($brand !== null && $brand !== '' && str_starts_with(mb_strtoupper($typ), mb_strtoupper($brand))) {
            $typ = trim(mb_substr($typ, mb_strlen($brand)));
        }
        // "ŠKODA FELICIA COMBI GLXI TMBEFF654 / EFF654" → first segment before VIN-like token
        if (preg_match('/^(.+?)\s+[A-Z0-9]{8,}\s*\//u', $typ, $m) === 1) {
            return trim($m[1]);
        }

        return $typ;
    }

    private function normalizeFuel(string $raw): string
    {
        $v = mb_strtolower(trim($raw));
        return match (true) {
            str_contains($v, 'benzin') || str_contains($v, 'petrol') || str_contains($v, 'gasoline')
                || str_starts_with($v, 'ba ') || preg_match('/\bba\b/', $v) === 1 => 'petrol',
            str_contains($v, 'nafta') || str_contains($v, 'diesel') || str_starts_with($v, 'nm')
                || preg_match('/\bnm\b/', $v) === 1 => 'diesel',
            str_contains($v, 'lpg') => 'lpg',
            str_contains($v, 'cng') => 'cng',
            str_contains($v, 'hybrid') => 'hybrid',
            str_contains($v, 'elektro') || str_contains($v, 'electric') => 'electric',
            default => $raw,
        };
    }

    /** @param array<string, mixed> $data */
    private function fuelFromFlags(array $data): ?string
    {
        $hybrid = mb_strtoupper(trim((string) ($data['VozidloHybridni'] ?? '')));
        $electric = mb_strtoupper(trim((string) ($data['VozidloElektricke'] ?? '')));
        if ($hybrid === 'ANO' || $hybrid === 'YES' || $hybrid === '1') {
            return 'hybrid';
        }
        if ($electric === 'ANO' || $electric === 'YES' || $electric === '1') {
            return 'electric';
        }

        return null;
    }

    private function normalizeGearbox(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        if (str_contains($v, 'auto') || str_contains($v, 'automat') || str_contains($v, 'dsg') || str_contains($v, 'tiptronic')) {
            return 'automatic';
        }
        if (str_contains($v, 'manu') || str_contains($v, 'manual')) {
            return 'manual';
        }

        return null;
    }

    /**
     * Převodovka v RSV často chybí jako samostatné pole — odvozujeme z Verze/Varianta (VW/Škoda kódy).
     *
     * @param array<string, mixed> $data
     */
    private function resolveGearbox(array $data): ?string
    {
        $explicit = $this->pick($data, ['Prevodovka', 'prevodovka', 'Gearbox', 'gearbox', 'PrevodovkaDruh']);
        if ($explicit !== null) {
            $mapped = $this->normalizeGearbox((string) $explicit);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        $hay = mb_strtoupper(implode(' ', array_filter([
            (string) ($data['Verze'] ?? ''),
            (string) ($data['Varianta'] ?? ''),
            (string) ($data['Typ'] ?? ''),
            (string) ($data['DalsiZaznamy'] ?? ''),
        ])));
        if ($hay === '') {
            return null;
        }

        // Automat / DSG / Tiptronic (VW group)
        if (preg_match('/\b(DSG|DQ\d{2,3}|S-?TRONIC|TIPTRONIC|AUTOMAT|0AM|0CW|02E|0D9|AQ\d{3}|AT\d)\b/', $hay) === 1
            || str_contains($hay, 'AUTOMAT') || str_contains($hay, 'DSG')) {
            return 'automatic';
        }
        // Manuál: FM5/FM6 (časté ve Verze, např. NFM5FM5…), MQ…, M5/M6
        if (preg_match('/FM[456]|MQ\d{2,3}|\bM[456]\b|02[JTQR]|0A4/', $hay) === 1
            || str_contains($hay, 'MANU')) {
            return 'manual';
        }

        return null;
    }

    private function parsePowerKw(mixed $raw): ?int
    {
        if ($raw === null || (string) $raw === '') {
            return null;
        }
        $s = trim((string) $raw);
        if (preg_match('/^(\d+)/', $s, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function parseSeats(mixed $raw): ?int
    {
        if ($raw === null || (string) $raw === '') {
            return null;
        }
        // "5 / 5 / 0"
        if (preg_match('/(\d+)/', (string) $raw, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function parseTireSize(mixed $raw): ?string
    {
        if ($raw === null || (string) $raw === '') {
            return null;
        }
        $line = trim(explode("\n", (string) $raw)[0] ?? '');
        $line = trim(explode(';', $line)[0] ?? '');
        // "195/65 R15 91H / 6J X 15 ET43"
        if (preg_match('/(\d{3}\/\d{2}\s*R\d{2}[^\/]*)/i', $line, $m) === 1) {
            return trim($m[1]);
        }
        $before = trim(explode('/', $line, 2)[0] ?? '');

        return $before !== '' ? $before : null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\SearchEngines\Application;

/**
 * Jednoduchý HTTP klient pro veřejné srovnávače / e-shopy.
 */
final class HttpFetch
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';

    /**
     * Lupa u OEM může poslat „OEM 1.800.204“ nebo „Název značka 1.800.204“.
     * E-shopy autodílů chtějí primárně katalogové číslo.
     */
    public static function normalizePartQuery(string $query): string
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
        if ($query === '') {
            return '';
        }
        if (preg_match('/^OEM\s+(.+)$/iu', $query, $m) === 1) {
            $query = trim($m[1]);
        }
        // „Olejový tlakový spínač FEBI 1.800.204“ → „1.800.204“
        if (str_contains($query, ' ')
            && preg_match('/(?:^|\s)([A-Z0-9][A-Z0-9.\-\/]{2,32})\s*$/u', $query, $m) === 1
            && preg_match('/\d/u', $m[1]) === 1
        ) {
            return $m[1];
        }

        return $query;
    }

    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, body: string, code: int, error: ?string}
     */
    public static function get(string $url, array $headers = [], int $timeout = 12, ?string $cookieFile = null): array
    {
        return self::request('GET', $url, null, $headers, $timeout, $cookieFile);
    }

    /**
     * @param array<string, mixed>|string|null $json
     * @param array<string, string> $headers
     * @return array{ok: bool, body: string, code: int, error: ?string}
     */
    public static function postJson(string $url, array|string|null $json, array $headers = [], int $timeout = 12, ?string $cookieFile = null): array
    {
        $body = is_string($json) ? $json : json_encode($json ?? new \stdClass(), JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['ok' => false, 'body' => '', 'code' => 0, 'error' => 'searchengines.error.request_failed'];
        }
        $headers = array_merge([
            'Content-Type: application/json;charset=UTF-8',
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest',
        ], $headers);

        return self::request('POST', $url, $body, $headers, $timeout, $cookieFile);
    }

    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, body: string, code: int, error: ?string}
     */
    private static function request(string $method, string $url, ?string $body, array $headers, int $timeout, ?string $cookieFile): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'body' => '', 'code' => 0, 'error' => 'searchengines.error.request_failed'];
        }
        $hdr = array_merge([
            'User-Agent: ' . self::UA,
            'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
            'Accept-Language: cs,en;q=0.8',
        ], $headers);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_ENCODING => '',
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($cookieFile !== null && $cookieFile !== '') {
            $opts[CURLOPT_COOKIEJAR] = $cookieFile;
            $opts[CURLOPT_COOKIEFILE] = $cookieFile;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 400) {
            return ['ok' => false, 'body' => is_string($raw) ? $raw : '', 'code' => $code, 'error' => 'searchengines.error.request_failed'];
        }

        return ['ok' => true, 'body' => $raw, 'code' => $code, 'error' => null];
    }

    public static function parseCzPrice(string $raw): ?float
    {
        $raw = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $raw = str_replace(["\xc2\xa0", "\u{202f}"], ' ', $raw);
        $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
        $raw = trim(str_ireplace(['Kč', 'CZK', '€', 'EUR'], '', $raw));
        if ($raw === '') {
            return null;
        }

        // 1.234.567,89 / 3.490,00 (tečka = tisíce, čárka = desetiny)
        if (preg_match('/^\d{1,3}(?:\.\d{3})+(,\d{1,2})?$/u', $raw) === 1) {
            $normalized = str_replace('.', '', $raw);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (preg_match('/^\d{1,3}(?: \d{3})+(,\d{1,2})?$/u', $raw) === 1) {
            // 3 634 / 3 634,50
            $normalized = str_replace(' ', '', $raw);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/u', $raw) === 1) {
            // 3.490 bez desetin → tisíce
            $normalized = str_replace('.', '', $raw);
        } elseif (str_contains($raw, ',') && !str_contains($raw, '.')) {
            // 97,50
            $normalized = str_replace(',', '.', str_replace(' ', '', $raw));
        } elseif (preg_match('/^\d+(?:\.\d{1,2})?$/u', $raw) === 1) {
            $normalized = $raw;
        } elseif (preg_match('/(\d{1,3}(?:[.\s]\d{3})+(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?)/u', $raw, $m) === 1) {
            return self::parseCzPrice($m[1]);
        } else {
            return null;
        }

        if (!is_numeric($normalized)) {
            return null;
        }
        $value = (float) $normalized;

        return $value > 0 ? $value : null;
    }
}

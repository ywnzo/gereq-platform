<?php

declare(strict_types=1);

namespace Gereq\Ares;

/**
 * Tenký HTTP klient na veřejné REST API ARES (MF ČR).
 *
 * @see https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/v3/api-docs
 */
final class AresClient
{
    private const BASE = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest';
    private const UA = 'GEREQ-Platform/0.1 (+ares; RealEstates)';

    /**
     * @return array<string, mixed>
     */
    public function getSubject(string $ico): array
    {
        $ico = $this->normalizeIco($ico);
        if ($ico === null) {
            throw new AresException('Neplatné IČO.', 422);
        }

        return $this->request('GET', '/ekonomicke-subjekty/' . rawurlencode($ico));
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public function searchSubjects(array $filter): array
    {
        return $this->request('POST', '/ekonomicke-subjekty/vyhledat', $filter);
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public function searchAddresses(array $filter): array
    {
        return $this->request('POST', '/standardizovane-adresy/vyhledat', $filter);
    }

    public function normalizeIco(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) > 8) {
            return null;
        }

        return str_pad($digits, 8, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = self::BASE . $path;
        $payload = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;

        if (function_exists('curl_init')) {
            return $this->viaCurl($method, $url, $payload);
        }

        return $this->viaStreams($method, $url, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function viaCurl(string $method, string $url, ?string $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new AresException('Nelze inicializovat cURL pro ARES.', 502);
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: ' . self::UA,
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new AresException('ARES není dostupný' . ($error !== '' ? ': ' . $error : '.'), 502);
        }

        return $this->decode((string) $raw, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function viaStreams(string $method, string $url, ?string $payload): array
    {
        $header = "Accept: application/json\r\nUser-Agent: " . self::UA . "\r\n";
        if ($payload !== null) {
            $header .= "Content-Type: application/json\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $header,
                'content' => $payload ?? '',
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new AresException('ARES není dostupný.', 502);
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) === 1) {
            $status = (int) $m[1];
        }

        return $this->decode($raw, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw, int $status): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new AresException('ARES vrátil neplatnou odpověď.', 502);
        }

        if ($status === 404) {
            throw new AresException('Subjekt nebyl v ARES nalezen.', 404);
        }

        if ($status >= 400) {
            $message = (string) ($decoded['popis'] ?? $decoded['message'] ?? 'Dotaz do ARES selhal.');
            $message = explode('|', $message)[0];
            throw new AresException($message, $status >= 500 ? 502 : 422);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

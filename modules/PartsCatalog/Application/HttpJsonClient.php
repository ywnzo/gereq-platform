<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application;

/**
 * Tenký HTTP klient pro TecDoc / AUTODOC / Srotas (JSON i HTML).
 * Každý request jde do storage/logs/partscatalog.log.
 */
final class HttpJsonClient
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     * @return array{status: int, body: string, json: mixed}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $body = null,
        string $contentType = 'application/json',
        int $timeout = 45,
        ?string $cookieFile = null,
    ): array {
        $started = microtime(true);
        $payload = null;
        if (is_array($body)) {
            $payload = $contentType === 'application/x-www-form-urlencoded'
                ? http_build_query($body)
                : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } elseif (is_string($body)) {
            $payload = $body;
        }

        $hasAccept = false;
        foreach ($headers as $h) {
            if (str_starts_with(strtolower($h), 'accept:')) {
                $hasAccept = true;
                break;
            }
        }
        $headerLines = array_merge(
            $hasAccept ? ['User-Agent: ' . self::UA] : ['Accept: application/json', 'User-Agent: ' . self::UA],
            $headers,
        );
        if ($payload !== null) {
            $headerLines[] = 'Content-Type: ' . $contentType;
        }

        $status = 0;
        $raw = '';
        $effective = $url;

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                if ($ch === false) {
                    throw new \RuntimeException('Nelze inicializovat cURL.');
                }
                $opts = [
                    CURLOPT_CUSTOMREQUEST => strtoupper($method),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 12,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_HTTPHEADER => $headerLines,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_ENCODING => '',
                ];
                if ($cookieFile !== null && $cookieFile !== '') {
                    $opts[CURLOPT_COOKIEJAR] = $cookieFile;
                    $opts[CURLOPT_COOKIEFILE] = $cookieFile;
                }
                curl_setopt_array($ch, $opts);
                $exec = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                curl_close($ch);
                if ($exec === false) {
                    CatalogCallLogger::log('http.error', [
                        'method' => strtoupper($method),
                        'url' => $url,
                        'effective' => $effective,
                        'timeout' => $timeout,
                        'cookieFile' => $cookieFile !== null,
                        'error' => $curlError,
                        'ms' => round((microtime(true) - $started) * 1000, 1),
                        'bodyPreview' => is_string($payload) ? mb_substr($payload, 0, 400) : null,
                    ]);
                    throw new \RuntimeException('HTTP selhalo' . ($curlError !== '' ? ': ' . $curlError : '.'));
                }
                $raw = (string) $exec;
            } else {
                $ctx = stream_context_create([
                    'http' => [
                        'method' => strtoupper($method),
                        'header' => implode("\r\n", $headerLines),
                        'content' => $payload ?? '',
                        'timeout' => $timeout,
                        'ignore_errors' => true,
                    ],
                ]);
                $exec = @file_get_contents($url, false, $ctx);
                if ($exec === false) {
                    CatalogCallLogger::log('http.error', [
                        'method' => strtoupper($method),
                        'url' => $url,
                        'timeout' => $timeout,
                        'error' => 'file_get_contents_failed',
                        'ms' => round((microtime(true) - $started) * 1000, 1),
                    ]);
                    throw new \RuntimeException('HTTP selhalo.');
                }
                $raw = (string) $exec;
                $status = 0;
                if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) === 1) {
                    $status = (int) $m[1];
                }
            }
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'HTTP selhalo')) {
                CatalogCallLogger::log('http.exception', [
                    'method' => strtoupper($method),
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'ms' => round((microtime(true) - $started) * 1000, 1),
                ]);
            }
            throw $e;
        }

        $json = null;
        if ($raw !== '') {
            try {
                $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $json = null;
            }
        }

        $turnstile = str_contains($raw, 'turnstile') || str_contains($raw, 'challenges.cloudflare.com');
        CatalogCallLogger::log('http', [
            'method' => strtoupper($method),
            'url' => $url,
            'effective' => $effective,
            'status' => $status,
            'ms' => round((microtime(true) - $started) * 1000, 1),
            'bytes' => strlen($raw),
            'json' => is_array($json),
            'turnstile' => $turnstile,
            'cookieFile' => $cookieFile !== null && $cookieFile !== '',
            'reqBody' => is_string($payload) ? mb_substr($payload, 0, 500) : null,
            'bodyHead' => mb_substr(preg_replace('/\s+/', ' ', $raw) ?? $raw, 0, 350),
        ]);

        return ['status' => $status, 'body' => $raw, 'json' => $json];
    }
}

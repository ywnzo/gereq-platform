<?php

declare(strict_types=1);

namespace Modules\Translate\Application;

/** Tenký HTTP klient (curl / streams) pro JSON API. */
final class HttpJsonClient
{
    private const UA = 'GEREQ-Platform/1.0 (+translate)';

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
        int $timeout = 120,
    ): array {
        $payload = null;
        if (is_array($body)) {
            $payload = $contentType === 'application/x-www-form-urlencoded'
                ? http_build_query($body)
                : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } elseif (is_string($body)) {
            $payload = $body;
        }

        $headerLines = array_merge([
            'Accept: application/json',
            'User-Agent: ' . self::UA,
        ], $headers);
        if ($payload !== null) {
            $headerLines[] = 'Content-Type: ' . $contentType;
        }

        if (function_exists('curl_init')) {
            return $this->viaCurl($method, $url, $headerLines, $payload, $timeout);
        }

        return $this->viaStreams($method, $url, $headerLines, $payload, $timeout);
    }

    /**
     * @param list<string> $headerLines
     * @return array{status: int, body: string, json: mixed}
     */
    private function viaCurl(string $method, string $url, array $headerLines, ?string $payload, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Nelze inicializovat cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('HTTP požadavek selhal' . ($error !== '' ? ': ' . $error : '.'));
        }

        return $this->pack($status, (string) $raw);
    }

    /**
     * @param list<string> $headerLines
     * @return array{status: int, body: string, json: mixed}
     */
    private function viaStreams(string $method, string $url, array $headerLines, ?string $payload, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $payload ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new \RuntimeException('HTTP požadavek selhal.');
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) === 1) {
            $status = (int) $m[1];
        }

        return $this->pack($status, $raw);
    }

    /**
     * @return array{status: int, body: string, json: mixed}
     */
    private function pack(int $status, string $raw): array
    {
        $json = null;
        if ($raw !== '') {
            try {
                $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $json = null;
            }
        }

        return ['status' => $status, 'body' => $raw, 'json' => $json];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

/** Minimal HTTP GET helper for public event feeds/pages. */
final class HttpFetcher
{
    public function __construct(
        private readonly string $userAgent = 'KdeAco-EventsImport/1.0 (+https://demo.kdeaco.cz)',
        private readonly int $timeoutSeconds = 25,
    ) {
    }

    public function get(string $url, ?string $encoding = null): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Neplatná URL pro import: ' . $url);
        }

        if (function_exists('curl_init')) {
            $body = $this->viaCurl($url);
        } else {
            $body = $this->viaStream($url);
        }

        if ($encoding !== null && $encoding !== '' && $encoding !== 'UTF-8') {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $body);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $body;
    }

    /** @return array<string, mixed> */
    public function getJson(string $url): array
    {
        $raw = $this->get($url);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Neplatná JSON odpověď z ' . $url);
        }

        return $decoded;
    }

    private function viaCurl(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init selhal pro ' . $url);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/json,*/*;q=0.8',
                'Accept-Language: cs,en;q=0.8',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('Prázdná odpověď z ' . $url . ($err !== '' ? ' (' . $err . ')' : ''));
        }
        if ($code >= 400) {
            throw new \RuntimeException('HTTP ' . $code . ' z ' . $url);
        }

        return $body;
    }

    private function viaStream(string $url): string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "User-Agent: {$this->userAgent}\r\nAccept: text/html,application/json,*/*\r\n",
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('Nepodařilo se stáhnout ' . $url);
        }

        return $body;
    }
}

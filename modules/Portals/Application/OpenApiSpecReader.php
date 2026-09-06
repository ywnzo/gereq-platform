<?php

declare(strict_types=1);

namespace Modules\Portals\Application;

/**
 * Načte OpenAPI 3 / Swagger 2 JSON z URL a vytáhne endpointy (method, path, formáty).
 */
final class OpenApiSpecReader
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /**
     * @return array{
     *   ok: bool,
     *   error: ?string,
     *   title: ?string,
     *   version: ?string,
     *   servers: list<string>,
     *   groups: array<string, list<array{method: string, path: string, summary: string, formats: list<string>}>>
     * }
     */
    public function read(?string $url): array
    {
        $empty = [
            'ok' => false,
            'error' => null,
            'title' => null,
            'version' => null,
            'servers' => [],
            'groups' => [],
        ];

        $url = $url !== null ? trim($url) : '';
        if ($url === '') {
            $empty['error'] = 'portals.providers.api.empty';

            return $empty;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            $empty['error'] = 'portals.providers.api.invalid_url';

            return $empty;
        }

        $fetched = $this->fetch($url);
        if (!$fetched['ok']) {
            $empty['error'] = 'portals.providers.api.fetch_failed';

            return $empty;
        }

        $decoded = json_decode($fetched['body'], true);
        if (!is_array($decoded)) {
            $empty['error'] = 'portals.providers.api.invalid_json';

            return $empty;
        }

        $info = is_array($decoded['info'] ?? null) ? $decoded['info'] : [];
        $servers = $this->servers($decoded);
        $groups = $this->operations($decoded);

        return [
            'ok' => true,
            'error' => null,
            'title' => isset($info['title']) ? trim((string) $info['title']) : null,
            'version' => isset($info['version']) ? trim((string) $info['version']) : null,
            'servers' => $servers,
            'groups' => $groups,
        ];
    }

    /**
     * @return array{ok: bool, body: string, code: int}
     */
    private function fetch(string $url): array
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 12,
                    'header' => "Accept: application/json, application/vnd.oai.openapi+json, */*\r\nUser-Agent: GEREQ-Portals/1.0\r\n",
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);

            return [
                'ok' => is_string($body) && $body !== '',
                'body' => is_string($body) ? $body : '',
                'code' => is_string($body) ? 200 : 0,
            ];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'body' => '', 'code' => 0];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, application/vnd.oai.openapi+json, text/plain, */*',
                'User-Agent: GEREQ-Portals/1.0',
            ],
            CURLOPT_ENCODING => '',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => is_string($raw) && $raw !== '' && $code >= 200 && $code < 400,
            'body' => is_string($raw) ? $raw : '',
            'code' => $code,
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @return list<string>
     */
    private function servers(array $spec): array
    {
        $out = [];
        if (isset($spec['servers']) && is_array($spec['servers'])) {
            foreach ($spec['servers'] as $server) {
                if (!is_array($server)) {
                    continue;
                }
                $url = trim((string) ($server['url'] ?? ''));
                if ($url !== '') {
                    $out[] = $url;
                }
            }
        }
        if ($out === [] && isset($spec['host'])) {
            $scheme = trim((string) ($spec['schemes'][0] ?? 'https'));
            $base = trim((string) ($spec['basePath'] ?? ''));
            $out[] = $scheme . '://' . trim((string) $spec['host']) . $base;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, list<array{method: string, path: string, summary: string, formats: list<string>}>>
     */
    private function operations(array $spec): array
    {
        $paths = $spec['paths'] ?? null;
        if (!is_array($paths)) {
            return [];
        }

        $groups = [];
        foreach ($paths as $path => $item) {
            if (!is_array($item) || !is_string($path)) {
                continue;
            }
            foreach (self::METHODS as $method) {
                if (!isset($item[$method]) || !is_array($item[$method])) {
                    continue;
                }
                $op = $item[$method];
                $summary = trim((string) ($op['summary'] ?? $op['operationId'] ?? $op['description'] ?? ''));
                $tags = [];
                if (isset($op['tags']) && is_array($op['tags'])) {
                    foreach ($op['tags'] as $tag) {
                        $t = trim((string) $tag);
                        if ($t !== '') {
                            $tags[] = $t;
                        }
                    }
                }
                if ($tags === []) {
                    $tags[] = 'default';
                }
                $formats = $this->formats($op, $spec);
                $row = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'summary' => $summary,
                    'formats' => $formats,
                ];
                foreach ($tags as $tag) {
                    $groups[$tag][] = $row;
                }
            }
        }
        ksort($groups);

        return $groups;
    }

    /**
     * @param array<string, mixed> $op
     * @param array<string, mixed> $spec
     * @return list<string>
     */
    private function formats(array $op, array $spec): array
    {
        $mimes = [];
        foreach (['produces', 'consumes'] as $key) {
            if (!isset($op[$key]) || !is_array($op[$key])) {
                continue;
            }
            foreach ($op[$key] as $mime) {
                $mimes[] = (string) $mime;
            }
        }
        if (isset($op['requestBody']) && is_array($op['requestBody'])) {
            $content = $op['requestBody']['content'] ?? null;
            if (is_array($content)) {
                $mimes = array_merge($mimes, array_map('strval', array_keys($content)));
            }
        }
        if (isset($op['responses']) && is_array($op['responses'])) {
            foreach ($op['responses'] as $response) {
                if (!is_array($response)) {
                    continue;
                }
                $content = $response['content'] ?? null;
                if (is_array($content)) {
                    $mimes = array_merge($mimes, array_map('strval', array_keys($content)));
                }
            }
        }
        // Swagger 2 global fallback
        foreach (['produces', 'consumes'] as $key) {
            if (!isset($spec[$key]) || !is_array($spec[$key])) {
                continue;
            }
            foreach ($spec[$key] as $mime) {
                $mimes[] = (string) $mime;
            }
        }

        $labels = [];
        foreach ($mimes as $mime) {
            $label = $this->mimeLabel($mime);
            if ($label !== null) {
                $labels[$label] = true;
            }
        }

        return array_keys($labels);
    }

    private function mimeLabel(string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        if ($mime === '') {
            return null;
        }
        if (str_contains($mime, 'json')) {
            return 'json';
        }
        if (str_contains($mime, 'xml')) {
            return 'xml';
        }
        if (str_contains($mime, 'yaml') || str_contains($mime, 'yml')) {
            return 'yaml';
        }
        if (str_contains($mime, 'form-urlencoded') || str_contains($mime, 'multipart')) {
            return 'form';
        }
        if (str_contains($mime, 'text/plain')) {
            return 'text';
        }
        if (str_contains($mime, 'octet-stream') || str_contains($mime, 'pdf') || str_contains($mime, 'image/')) {
            return 'binary';
        }

        return null;
    }
}

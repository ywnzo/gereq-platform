<?php

declare(strict_types=1);

namespace Modules\SearchEngines\Application\Drivers;

/**
 * Heureka.cz — veřejné hledání přes curl_cffi (Chrome TLS).
 */
final class HeurekaClient
{
    private const SCRIPT = __DIR__ . '/../../bin/heureka_search.py';

    public function configured(): bool
    {
        return $this->pythonAvailable();
    }

    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string, has_more: bool}
     */
    public function search(string $query, int $limit = 40, int $batch = 1): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.query_required', 'has_more' => false];
        }
        if (!$this->configured()) {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.heureka_unavailable', 'has_more' => false];
        }

        return $this->searchViaPython($query, max(1, $batch), max(1, min(80, $limit)));
    }

    public static function searchUrl(string $query): string
    {
        return 'https://www.heureka.cz/?h%5Bfraze%5D=' . rawurlencode(trim($query));
    }

    /**
     * @return array{ok: bool, offers: list<array<string, mixed>>, error: ?string}
     */
    public function offers(string $productUrl): array
    {
        $productUrl = trim($productUrl);
        if ($productUrl === '') {
            return ['ok' => false, 'offers' => [], 'error' => 'searchengines.error.query_required'];
        }
        if (!$this->configured()) {
            return ['ok' => false, 'offers' => [], 'error' => 'searchengines.error.heureka_unavailable'];
        }
        $bin = $this->pythonBin();
        if ($bin === null) {
            return ['ok' => false, 'offers' => [], 'error' => 'searchengines.error.request_failed'];
        }
        $cmd = escapeshellcmd($bin)
            . ' ' . escapeshellarg(self::SCRIPT)
            . ' --offers '
            . escapeshellarg($productUrl)
            . ' 2>/dev/null';
        $raw = shell_exec($cmd);
        if (!is_string($raw) || trim($raw) === '') {
            return ['ok' => false, 'offers' => [], 'error' => 'searchengines.error.request_failed'];
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(trim($raw), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'offers' => [], 'error' => 'searchengines.error.invalid'];
        }
        $offers = [];
        if (isset($decoded['offers']) && is_array($decoded['offers'])) {
            foreach ($decoded['offers'] as $row) {
                if (is_array($row) && trim((string) ($row['shop'] ?? '')) !== '') {
                    $offers[] = $row;
                }
            }
        }

        return [
            'ok' => (bool) ($decoded['ok'] ?? false) && $offers !== [],
            'offers' => $offers,
            'error' => isset($decoded['error']) && is_string($decoded['error']) ? $decoded['error'] : null,
        ];
    }

    private function pythonAvailable(): bool
    {
        if (!is_file(self::SCRIPT) || !is_readable(self::SCRIPT)) {
            return false;
        }
        foreach (['python3', 'python'] as $bin) {
            $out = [];
            $code = 1;
            @exec(escapeshellcmd($bin) . ' -c ' . escapeshellarg('import curl_cffi') . ' 2>/dev/null', $out, $code);
            if ($code === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{ok: bool, items: list<array<string, mixed>>, error: ?string, has_more: bool}
     */
    private function searchViaPython(string $query, int $batch, int $limit): array
    {
        $bin = $this->pythonBin();
        if ($bin === null) {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.request_failed', 'has_more' => false];
        }
        $cmd = escapeshellcmd($bin)
            . ' ' . escapeshellarg(self::SCRIPT)
            . ' ' . escapeshellarg($query)
            . ' ' . escapeshellarg((string) $batch)
            . ' ' . escapeshellarg((string) $limit)
            . ' 2>/dev/null';
        $raw = shell_exec($cmd);
        if (!is_string($raw) || trim($raw) === '') {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.request_failed', 'has_more' => false];
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(trim($raw), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'items' => [], 'error' => 'searchengines.error.invalid', 'has_more' => false];
        }
        $items = [];
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            foreach ($decoded['items'] as $row) {
                if (is_array($row) && trim((string) ($row['name'] ?? '')) !== '') {
                    $items[] = $row;
                }
            }
        }

        return [
            'ok' => (bool) ($decoded['ok'] ?? false) && $items !== [],
            'items' => $items,
            'error' => isset($decoded['error']) && is_string($decoded['error']) ? $decoded['error'] : null,
            'has_more' => (bool) ($decoded['has_more'] ?? false),
        ];
    }

    private function pythonBin(): ?string
    {
        foreach (['python3', 'python'] as $bin) {
            $out = [];
            $code = 1;
            @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
            if ($code === 0 && isset($out[0]) && $out[0] !== '') {
                return $out[0];
            }
        }

        return null;
    }
}

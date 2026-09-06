<?php

declare(strict_types=1);

namespace Modules\Translate\Application;

use Gereq\Setting\Settings;

/**
 * Překlad přes Azure / Microsoft Translator Text API v3.
 *
 * @see https://learn.microsoft.com/azure/ai-services/translator/text-translation/reference/v3/translate
 */
final class MicrosoftTranslateProvider implements TranslateProviderInterface
{
    private const DEFAULT_ENDPOINT = 'https://api.cognitive.microsofttranslator.com';
    private const API_VERSION = '3.0';
    private const MAX_TEXTS_PER_REQUEST = 100;
    private const HTTP_TIMEOUT = 30;

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpJsonClient $http,
    ) {
    }

    public function isConfigured(): bool
    {
        $key = $this->apiKey();
        TranslateDebug::log('isConfigured', [
            'hasKey' => $key !== '',
            'keyPrefix' => $key !== '' ? mb_substr($key, 0, 8) . '…' : null,
            'region' => $this->region() !== '' ? $this->region() : null,
            'endpoint' => $this->endpoint(),
        ]);

        return $key !== '';
    }

    public function ping(): array
    {
        TranslateDebug::clear();
        TranslateDebug::log('ping.start');

        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Microsoft Translator klíč chybí (nastavení ani MS_TRANSLATOR_KEY / AZURE_TRANSLATOR_KEY).',
            ];
        }

        try {
            $t0 = microtime(true);
            $out = $this->translateBatch(['ping'], 'en', 'cs');
            $ms = (int) ((microtime(true) - $t0) * 1000);
            $sample = $out[0] ?? '';
            $details = [
                'ms' => $ms,
                'sample' => $sample,
                'region' => $this->region() !== '' ? $this->region() : null,
                'endpoint' => $this->endpoint(),
            ];
            TranslateDebug::log('ping.ok', $details);

            return [
                'ok' => true,
                'message' => 'Microsoft Translator OK — překlad en→cs „ping“ → „'
                    . $sample . '“ za ' . $ms . ' ms.',
                'details' => $details,
            ];
        } catch (\Throwable $e) {
            TranslateDebug::log('ping.error', [
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'Microsoft Translator selhalo: ' . $e->getMessage(),
                'details' => ['exception' => $e::class],
            ];
        }
    }

    public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): array
    {
        TranslateDebug::log('translateBatch.in', [
            'count' => count($texts),
            'from' => $sourceLocale,
            'to' => $targetLocale,
            'sample' => array_slice($texts, 0, 5),
        ]);

        if ($texts === []) {
            return [];
        }
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Microsoft Translator klíč není nastaven (Translate).');
        }

        $from = $this->normalizeLocale($sourceLocale);
        $to = $this->normalizeLocale($targetLocale);
        $out = [];

        foreach (array_chunk(array_values($texts), self::MAX_TEXTS_PER_REQUEST) as $chunk) {
            $translated = $this->requestTranslate($chunk, $from, $to);
            foreach ($translated as $row) {
                $out[] = $row;
            }
        }

        TranslateDebug::log('translateBatch.out', [
            'returned' => count($out),
            'sample' => array_slice($out, 0, 5),
        ]);

        return $out;
    }

    /**
     * @param list<string> $texts
     * @return list<string>
     */
    private function requestTranslate(array $texts, string $from, string $to): array
    {
        $query = http_build_query([
            'api-version' => self::API_VERSION,
            'from' => $from,
            'to' => $to,
        ]);
        $url = rtrim($this->endpoint(), '/') . '/translate?' . $query;

        $body = [];
        foreach ($texts as $text) {
            $body[] = ['Text' => $text];
        }

        $headers = [
            'Ocp-Apim-Subscription-Key: ' . $this->apiKey(),
        ];
        $region = $this->region();
        if ($region !== '') {
            $headers[] = 'Ocp-Apim-Subscription-Region: ' . $region;
        }

        TranslateDebug::log('http.request', [
            'url' => $url,
            'count' => count($texts),
            'hasRegion' => $region !== '',
        ]);

        $t0 = microtime(true);
        $res = $this->http->request('POST', $url, $headers, $body, timeout: self::HTTP_TIMEOUT);
        TranslateDebug::log('http.response', [
            'status' => $res['status'],
            'ms' => (int) ((microtime(true) - $t0) * 1000),
            'bodyHead' => mb_substr($res['body'], 0, 280),
        ]);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            $snippet = mb_substr($res['body'], 0, 240);
            throw new \RuntimeException('Microsoft Translator HTTP ' . $res['status'] . ($snippet !== '' ? ': ' . $snippet : ''));
        }
        if (!is_array($res['json']) || !array_is_list($res['json'])) {
            throw new \RuntimeException('Microsoft Translator nevrátilo očekávané pole překladů.');
        }

        $out = [];
        foreach ($res['json'] as $i => $item) {
            $text = null;
            if (is_array($item) && isset($item['translations'][0]['text']) && is_string($item['translations'][0]['text'])) {
                $text = $item['translations'][0]['text'];
            }
            $out[] = $text !== null && $text !== '' ? $text : ($texts[$i] ?? '');
        }

        if (count($out) !== count($texts)) {
            throw new \RuntimeException(
                'Microsoft Translator vrátilo ' . count($out) . ' položek, očekáváno ' . count($texts) . '.',
            );
        }

        return $out;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));
        if ($locale === '') {
            return $locale;
        }
        $parts = explode('-', $locale);

        return $parts[0] !== '' ? $parts[0] : $locale;
    }

    private function apiKey(): string
    {
        $fromSettings = trim((string) $this->settings->get('translate.ms_api_key', ''));
        if ($fromSettings !== '') {
            return $fromSettings;
        }

        $env = trim((string) (getenv('MS_TRANSLATOR_KEY') ?: ''));
        if ($env !== '') {
            return $env;
        }

        return trim((string) (getenv('AZURE_TRANSLATOR_KEY') ?: ''));
    }

    private function region(): string
    {
        $fromSettings = trim((string) $this->settings->get('translate.ms_region', ''));
        if ($fromSettings !== '') {
            return $fromSettings;
        }

        return trim((string) (getenv('MS_TRANSLATOR_REGION') ?: getenv('AZURE_TRANSLATOR_REGION') ?: ''));
    }

    private function endpoint(): string
    {
        $fromSettings = trim((string) $this->settings->get('translate.ms_endpoint', ''));
        if ($fromSettings !== '') {
            return rtrim($fromSettings, '/');
        }

        $env = trim((string) (getenv('MS_TRANSLATOR_ENDPOINT') ?: ''));
        if ($env !== '') {
            return rtrim($env, '/');
        }

        return self::DEFAULT_ENDPOINT;
    }
}

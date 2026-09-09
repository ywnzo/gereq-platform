<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Providers;

use Gereq\Setting\Settings;
use Modules\Ai\Application\Contracts\EmbeddingProvider;
use Modules\Ai\Application\Contracts\LlmProvider;
use Modules\Ai\Application\Contracts\StreamableLlm;
use Modules\Ai\Application\Dto\ChatRequest;
use Modules\Ai\Application\Dto\ChatResponse;

final class GeminiLlmProvider implements LlmProvider, EmbeddingProvider, StreamableLlm
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function driver(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->get('ai.gemini_api_key', '')) !== '';
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $key = trim((string) $this->settings->get('ai.gemini_api_key', ''));
        $model = $request->model;
        $contents = [];
        $system = '';
        foreach ($request->messages as $m) {
            if ($m->role === 'system') {
                $system .= ($system === '' ? '' : "\n") . $m->content;
                continue;
            }
            $role = $m->role === 'assistant' ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => $m->content]]];
        }
        $payload = [
            'contents' => $contents,
            'generationConfig' => ['temperature' => $request->temperature],
        ];
        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        if ($request->tools !== []) {
            $decls = [];
            foreach ($request->tools as $t) {
                $fn = $t['function'] ?? $t;
                $decls[] = [
                    'name' => $fn['name'],
                    'description' => $fn['description'] ?? '',
                    'parameters' => $fn['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ];
            }
            $payload['tools'] = [['functionDeclarations' => $decls]];
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($model),
            rawurlencode($key),
        );
        $data = $this->http($url, $payload);
        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];
        $text = '';
        $toolCalls = [];
        foreach ($parts as $i => $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
            if (isset($part['functionCall'])) {
                $fc = $part['functionCall'];
                $toolCalls[] = [
                    'id' => 'gemini_' . $i,
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($fc['name'] ?? ''),
                        'arguments' => json_encode($fc['args'] ?? new \stdClass(), JSON_THROW_ON_ERROR),
                    ],
                ];
            }
        }
        $usage = $data['usageMetadata'] ?? [];

        return new ChatResponse(
            content: $text,
            toolCalls: $toolCalls,
            tokensIn: (int) ($usage['promptTokenCount'] ?? 0),
            tokensOut: (int) ($usage['candidatesTokenCount'] ?? 0),
            finishReason: isset($candidate['finishReason']) ? (string) $candidate['finishReason'] : null,
            rawModel: $model,
        );
    }

    public function chatStream(ChatRequest $request, callable $onDelta): ChatResponse
    {
        // Tool rounds need a complete response — use non-stream path.
        if ($request->tools !== []) {
            $response = $this->chat($request);
            if (!$response->hasToolCalls()) {
                $this->fakeStream($response->content, $onDelta);
            }

            return $response;
        }

        $key = trim((string) $this->settings->get('ai.gemini_api_key', ''));
        $model = $request->model;
        $payload = $this->buildPayload($request);
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:streamGenerateContent?alt=sse&key=%s',
            rawurlencode($model),
            rawurlencode($key),
        );

        $text = '';
        $tokensIn = 0;
        $tokensOut = 0;
        $finishReason = null;
        $buffer = '';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, &$text, &$tokensIn, &$tokensOut, &$finishReason, $onDelta): int {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || !str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }
                    $json = json_decode($data, true);
                    if (!is_array($json)) {
                        continue;
                    }
                    $candidate = $json['candidates'][0] ?? [];
                    if (isset($candidate['finishReason'])) {
                        $finishReason = (string) $candidate['finishReason'];
                    }
                    foreach ($candidate['content']['parts'] ?? [] as $part) {
                        if (!isset($part['text']) || !is_string($part['text']) || $part['text'] === '') {
                            continue;
                        }
                        $text .= $part['text'];
                        $onDelta($part['text']);
                    }
                    $usage = $json['usageMetadata'] ?? [];
                    if (is_array($usage)) {
                        $tokensIn = (int) ($usage['promptTokenCount'] ?? $tokensIn);
                        $tokensOut = (int) ($usage['candidatesTokenCount'] ?? $tokensOut);
                    }
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            throw new \RuntimeException('Gemini stream failed: ' . $error);
        }
        if ($status >= 400 || $text === '') {
            // Fallback to classic generateContent.
            $response = $this->chat($request);
            $this->fakeStream($response->content, $onDelta);

            return $response;
        }

        return new ChatResponse(
            content: $text,
            toolCalls: [],
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            finishReason: $finishReason,
            rawModel: $model,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(ChatRequest $request): array
    {
        $contents = [];
        $system = '';
        foreach ($request->messages as $m) {
            if ($m->role === 'system') {
                $system .= ($system === '' ? '' : "\n") . $m->content;
                continue;
            }
            $role = $m->role === 'assistant' ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => $m->content]]];
        }
        $payload = [
            'contents' => $contents,
            'generationConfig' => ['temperature' => $request->temperature],
        ];
        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        if ($request->tools !== []) {
            $decls = [];
            foreach ($request->tools as $t) {
                $fn = $t['function'] ?? $t;
                $decls[] = [
                    'name' => $fn['name'],
                    'description' => $fn['description'] ?? '',
                    'parameters' => $fn['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ];
            }
            $payload['tools'] = [['functionDeclarations' => $decls]];
        }

        return $payload;
    }

    private function fakeStream(string $text, callable $onDelta): void
    {
        if ($text === '') {
            return;
        }
        $parts = preg_split('/(?<=\s)/u', $text) ?: [$text];
        foreach ($parts as $part) {
            if ($part !== '') {
                $onDelta($part);
            }
        }
    }

    public function embed(array $texts, string $modelId): array
    {
        $key = trim((string) $this->settings->get('ai.gemini_api_key', ''));
        $out = [];
        foreach ($texts as $text) {
            $url = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent?key=%s',
                rawurlencode($modelId),
                rawurlencode($key),
            );
            $data = $this->http($url, [
                'model' => 'models/' . $modelId,
                'content' => ['parts' => [['text' => $text]]],
            ]);
            $values = $data['embedding']['values'] ?? [];
            $out[] = array_map(static fn ($v) => (float) $v, is_array($values) ? $values : []);
        }

        return $out;
    }

    public function dimensions(string $modelId): ?int
    {
        return match (true) {
            str_contains($modelId, 'gemini-embedding') => 3072,
            str_contains($modelId, 'embedding') => 768,
            default => null,
        };
    }

    public function ping(): array
    {
        try {
            if (!$this->isConfigured()) {
                return ['ok' => false, 'message' => 'Gemini API key missing'];
            }
            $resp = $this->chat(new ChatRequest(
                model: 'gemini-3.6-flash',
                messages: [new \Modules\Ai\Application\Dto\ChatMessage('user', 'ping')],
                temperature: 0,
            ));

            return ['ok' => true, 'message' => 'OK', 'details' => ['preview' => mb_substr($resp->content, 0, 80)]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function http(string $url, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException('Empty Gemini response');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $status >= 400) {
            $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $body) : $body;
            throw new \RuntimeException('Gemini HTTP ' . $status . ': ' . $msg);
        }

        return $decoded;
    }
}

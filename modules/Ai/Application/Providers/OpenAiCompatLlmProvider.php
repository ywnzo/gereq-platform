<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Providers;

use Gereq\Setting\Settings;
use Modules\Ai\Application\Contracts\EmbeddingProvider;
use Modules\Ai\Application\Contracts\LlmProvider;
use Modules\Ai\Application\Contracts\StreamableLlm;
use Modules\Ai\Application\Dto\ChatRequest;
use Modules\Ai\Application\Dto\ChatResponse;

final class OpenAiCompatLlmProvider implements LlmProvider, EmbeddingProvider, StreamableLlm
{
    public function __construct(
        private readonly string $driverName,
        private readonly Settings $settings,
        private readonly string $apiKeySetting,
        private readonly string $defaultBaseUrl,
        private readonly ?string $baseUrlSetting = null,
    ) {
    }

    public function driver(): string
    {
        return $this->driverName;
    }

    public function isConfigured(): bool
    {
        if ($this->driverName === 'ollama') {
            return $this->ollamaReachable();
        }

        return trim((string) $this->settings->get($this->apiKeySetting, '')) !== '';
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $client = $this->client();
        $payload = [
            'model' => $request->model,
            'messages' => array_map(static fn ($m) => $m->toOpenAi(), $request->messages),
            'temperature' => $request->temperature,
        ];
        if ($request->tools !== []) {
            $payload['tools'] = $request->tools;
            $payload['tool_choice'] = 'auto';
        }
        foreach ($request->extra as $k => $v) {
            $payload[$k] = $v;
        }

        $data = $client->chatCompletions($payload);
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $toolCalls[] = [
                'id' => (string) ($tc['id'] ?? ''),
                'type' => (string) ($tc['type'] ?? 'function'),
                'function' => [
                    'name' => (string) ($tc['function']['name'] ?? ''),
                    'arguments' => is_string($tc['function']['arguments'] ?? null)
                        ? $tc['function']['arguments']
                        : json_encode($tc['function']['arguments'] ?? new \stdClass(), JSON_THROW_ON_ERROR),
                ],
            ];
        }

        $usage = $data['usage'] ?? [];

        return new ChatResponse(
            content: (string) ($message['content'] ?? ''),
            toolCalls: $toolCalls,
            tokensIn: (int) ($usage['prompt_tokens'] ?? 0),
            tokensOut: (int) ($usage['completion_tokens'] ?? 0),
            finishReason: isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : null,
            rawModel: isset($data['model']) ? (string) $data['model'] : null,
        );
    }

    public function chatStream(ChatRequest $request, callable $onDelta): ChatResponse
    {
        $client = $this->client();
        $payload = [
            'model' => $request->model,
            'messages' => array_map(static fn ($m) => $m->toOpenAi(), $request->messages),
            'temperature' => $request->temperature,
        ];
        if ($request->tools !== []) {
            $payload['tools'] = $request->tools;
            $payload['tool_choice'] = 'auto';
        }
        foreach ($request->extra as $k => $v) {
            $payload[$k] = $v;
        }

        $emitted = 0;
        $result = $client->chatCompletionsStream($payload, static function (array $json) use (&$emitted, $onDelta): void {
            $delta = $json['choices'][0]['delta']['content'] ?? null;
            if (is_string($delta) && $delta !== '') {
                $emitted += strlen($delta);
                $onDelta($delta);
            }
        });

        $toolCalls = [];
        foreach ($result['tool_calls'] as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $toolCalls[] = [
                'id' => (string) ($tc['id'] ?? ''),
                'type' => (string) ($tc['type'] ?? 'function'),
                'function' => [
                    'name' => (string) ($tc['function']['name'] ?? ''),
                    'arguments' => (string) ($tc['function']['arguments'] ?? ''),
                ],
            ];
        }

        // If provider ignored stream and returned nothing via deltas, fall back once.
        if ($result['content'] === '' && $toolCalls === [] && $emitted === 0) {
            $fallback = $this->chat($request);
            $this->fakeStream($fallback->content, $onDelta);

            return $fallback;
        }

        $usage = $result['usage'];

        return new ChatResponse(
            content: $result['content'],
            toolCalls: $toolCalls,
            tokensIn: (int) ($usage['prompt_tokens'] ?? 0),
            tokensOut: (int) ($usage['completion_tokens'] ?? 0),
            finishReason: $result['finish_reason'],
            rawModel: $result['model'],
        );
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
        if ($texts === []) {
            return [];
        }
        $client = $this->client();
        $path = $this->driverName === 'ollama' ? '/api/embed' : '/embeddings';

        if ($this->driverName === 'ollama') {
            $data = $this->ollamaEmbed($texts, $modelId);
            return $data;
        }

        $data = $client->embeddings([
            'model' => $modelId,
            'input' => $texts,
        ]);
        $rows = $data['data'] ?? [];
        usort($rows, static fn ($a, $b) => ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0)));
        $out = [];
        foreach ($rows as $row) {
            $emb = $row['embedding'] ?? [];
            $out[] = array_map(static fn ($v) => (float) $v, is_array($emb) ? $emb : []);
        }

        return $out;
    }

    public function dimensions(string $modelId): ?int
    {
        return match (true) {
            str_contains($modelId, 'text-embedding-3-small') => 1536,
            str_contains($modelId, 'text-embedding-3-large') => 3072,
            str_contains($modelId, 'nomic') => 768,
            str_contains($modelId, 'qwen3-embedding') => 1024,
            str_contains($modelId, 'bge-m3') => 1024,
            default => null,
        };
    }

    public function ping(): array
    {
        try {
            if (!$this->isConfigured()) {
                return ['ok' => false, 'message' => 'API key / endpoint not configured'];
            }
            $resp = $this->chat(new ChatRequest(
                model: $this->pingModel(),
                messages: [new \Modules\Ai\Application\Dto\ChatMessage('user', 'ping')],
                temperature: 0,
            ));

            return ['ok' => true, 'message' => 'OK', 'details' => ['preview' => mb_substr($resp->content, 0, 80)]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function client(): OpenAiCompatClient
    {
        $base = $this->defaultBaseUrl;
        if ($this->baseUrlSetting !== null) {
            $override = trim((string) $this->settings->get($this->baseUrlSetting, ''));
            if ($override !== '') {
                $base = $override;
            }
        }
        $key = $this->apiKeySetting !== ''
            ? trim((string) $this->settings->get($this->apiKeySetting, ''))
            : null;

        return new OpenAiCompatClient($base, $key !== '' ? $key : null);
    }

    private function pingModel(): string
    {
        return match ($this->driverName) {
            'ollama' => 'llama3.2',
            'zai' => 'glm-4.5-flash',
            'deepseek' => 'deepseek-chat',
            'groq' => 'openai/gpt-oss-20b',
            'cerebras' => 'llama3.1-8b',
            'openai_compat' => 'openrouter/free',
            default => 'gpt-4.1-mini',
        };
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    private function ollamaEmbed(array $texts, string $modelId): array
    {
        $base = $this->defaultBaseUrl;
        if ($this->baseUrlSetting !== null) {
            $override = trim((string) $this->settings->get($this->baseUrlSetting, ''));
            if ($override !== '') {
                $base = $override;
            }
        }
        $url = rtrim($base, '/') . '/api/embed';
        $payload = json_encode(['model' => $modelId, 'input' => $texts], JSON_THROW_ON_ERROR);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $status >= 400) {
            throw new \RuntimeException('Ollama embed failed (HTTP ' . $status . ')');
        }
        $data = json_decode($body, true);
        $embeddings = $data['embeddings'] ?? [];
        $out = [];
        foreach ($embeddings as $emb) {
            $out[] = array_map(static fn ($v) => (float) $v, is_array($emb) ? $emb : []);
        }

        return $out;
    }

    private function ollamaReachable(): bool
    {
        $base = $this->resolvedBaseUrl();
        $ch = curl_init(rtrim($base, '/') . '/api/tags');
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return is_string($body) && $status >= 200 && $status < 500;
    }

    private function resolvedBaseUrl(): string
    {
        $base = $this->defaultBaseUrl;
        if ($this->baseUrlSetting !== null) {
            $override = trim((string) $this->settings->get($this->baseUrlSetting, ''));
            if ($override !== '') {
                $base = $override;
            }
        }

        return $base;
    }
}

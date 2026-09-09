<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Providers;

use Gereq\Setting\Settings;
use Modules\Ai\Application\Contracts\LlmProvider;
use Modules\Ai\Application\Dto\ChatRequest;
use Modules\Ai\Application\Dto\ChatResponse;

final class AnthropicLlmProvider implements LlmProvider
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function driver(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->get('ai.anthropic_api_key', '')) !== '';
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $key = trim((string) $this->settings->get('ai.anthropic_api_key', ''));
        $system = '';
        $messages = [];
        foreach ($request->messages as $m) {
            if ($m->role === 'system') {
                $system .= ($system === '' ? '' : "\n\n") . $m->content;
                continue;
            }
            if ($m->role === 'tool') {
                $messages[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $m->toolCallId ?? '',
                        'content' => $m->content,
                    ]],
                ];
                continue;
            }
            if ($m->toolCalls !== null && $m->toolCalls !== []) {
                $content = [];
                if ($m->content !== '') {
                    $content[] = ['type' => 'text', 'text' => $m->content];
                }
                foreach ($m->toolCalls as $tc) {
                    $args = json_decode($tc['function']['arguments'], true);
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $tc['id'],
                        'name' => $tc['function']['name'],
                        'input' => is_array($args) ? $args : [],
                    ];
                }
                $messages[] = ['role' => 'assistant', 'content' => $content];
                continue;
            }
            $messages[] = ['role' => $m->role === 'assistant' ? 'assistant' : 'user', 'content' => $m->content];
        }

        $payload = [
            'model' => $request->model,
            'max_tokens' => 4096,
            'temperature' => $request->temperature,
            'messages' => $messages,
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }
        if ($request->tools !== []) {
            $payload['tools'] = array_map(static function (array $t): array {
                $fn = $t['function'] ?? $t;
                return [
                    'name' => $fn['name'],
                    'description' => $fn['description'] ?? '',
                    'input_schema' => $fn['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ];
            }, $request->tools);
        }

        $data = $this->request($key, $payload);
        $text = '';
        $toolCalls = [];
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
            if (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($block['name'] ?? ''),
                        'arguments' => json_encode($block['input'] ?? new \stdClass(), JSON_THROW_ON_ERROR),
                    ],
                ];
            }
        }
        $usage = $data['usage'] ?? [];

        return new ChatResponse(
            content: $text,
            toolCalls: $toolCalls,
            tokensIn: (int) ($usage['input_tokens'] ?? 0),
            tokensOut: (int) ($usage['output_tokens'] ?? 0),
            finishReason: isset($data['stop_reason']) ? (string) $data['stop_reason'] : null,
            rawModel: isset($data['model']) ? (string) $data['model'] : null,
        );
    }

    public function ping(): array
    {
        try {
            if (!$this->isConfigured()) {
                return ['ok' => false, 'message' => 'Anthropic API key missing'];
            }
            $resp = $this->chat(new ChatRequest(
                model: 'claude-haiku-4-5-20251001',
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
    private function request(string $key, array $payload): array
    {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException('Empty Anthropic response');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $status >= 400) {
            $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $body) : $body;
            throw new \RuntimeException('Anthropic HTTP ' . $status . ': ' . $msg);
        }

        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Providers;

/**
 * Shared OpenAI-compatible HTTP client (chat + embeddings).
 */
final class OpenAiCompatClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 90,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function chatCompletions(array $payload): array
    {
        return $this->request('POST', '/chat/completions', $payload);
    }

    /**
     * OpenAI-compatible SSE stream. Invokes $onEvent for each parsed JSON chunk.
     *
     * @param array<string, mixed> $payload
     * @param callable(array<string, mixed>): void $onEvent
     * @return array{content: string, tool_calls: list<array<string, mixed>>, finish_reason: ?string, model: ?string, usage: array<string, mixed>}
     */
    public function chatCompletionsStream(array $payload, callable $onEvent): array
    {
        $payload['stream'] = true;

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';
        $headers = [
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $content = '';
        $toolCalls = [];
        $finishReason = null;
        $model = null;
        $usage = [];
        $buffer = '';

        $write = function (string $chunk) use (&$buffer, &$content, &$toolCalls, &$finishReason, &$model, &$usage, $onEvent): int {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    continue;
                }
                $json = json_decode($data, true);
                if (!is_array($json)) {
                    continue;
                }
                $onEvent($json);
                if (isset($json['model']) && is_string($json['model'])) {
                    $model = $json['model'];
                }
                if (isset($json['usage']) && is_array($json['usage'])) {
                    $usage = $json['usage'];
                }
                $choice = $json['choices'][0] ?? null;
                if (!is_array($choice)) {
                    continue;
                }
                if (isset($choice['finish_reason']) && is_string($choice['finish_reason'])) {
                    $finishReason = $choice['finish_reason'];
                }
                $delta = $choice['delta'] ?? [];
                if (!is_array($delta)) {
                    continue;
                }
                if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                    $content .= $delta['content'];
                }
                foreach ($delta['tool_calls'] ?? [] as $tc) {
                    if (!is_array($tc)) {
                        continue;
                    }
                    $idx = (int) ($tc['index'] ?? 0);
                    if (!isset($toolCalls[$idx])) {
                        $toolCalls[$idx] = [
                            'id' => (string) ($tc['id'] ?? ''),
                            'type' => (string) ($tc['type'] ?? 'function'),
                            'function' => [
                                'name' => (string) ($tc['function']['name'] ?? ''),
                                'arguments' => (string) ($tc['function']['arguments'] ?? ''),
                            ],
                        ];
                    } else {
                        if (isset($tc['id'])) {
                            $toolCalls[$idx]['id'] = (string) $tc['id'];
                        }
                        if (isset($tc['function']['name'])) {
                            $toolCalls[$idx]['function']['name'] .= (string) $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $toolCalls[$idx]['function']['arguments'] .= (string) $tc['function']['arguments'];
                        }
                    }
                }
            }

            return strlen($chunk);
        };

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use ($write): int {
                return $write($data);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($ok === false || $errno !== 0) {
            throw new \RuntimeException('HTTP stream error: ' . $error);
        }
        if ($status >= 400) {
            throw new \RuntimeException('Provider HTTP ' . $status . ' during stream');
        }

        return [
            'content' => $content,
            'tool_calls' => array_values($toolCalls),
            'finish_reason' => $finishReason,
            'model' => $model,
            'usage' => $usage,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function embeddings(array $payload): array
    {
        return $this->request('POST', '/embeddings', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('HTTP error: ' . $error);
        }
        if (!is_string($body)) {
            throw new \RuntimeException('Empty provider response');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON from provider (HTTP ' . $status . ')');
        }
        if ($status >= 400) {
            $msg = (string) ($decoded['error']['message'] ?? $decoded['error'] ?? $body);
            throw new \RuntimeException('Provider HTTP ' . $status . ': ' . $msg);
        }

        return $decoded;
    }
}

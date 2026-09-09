<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Tools;

use Modules\Ai\Application\Contracts\AiTool;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;
use Modules\Ai\Domain\AiCustomTool;

/**
 * Runtime wrapper for admin-defined custom tools (HTTP webhook or static JSON).
 */
final class CustomToolRuntime implements AiTool
{
    public function __construct(private readonly AiCustomTool $definition)
    {
    }

    public function name(): string
    {
        return $this->definition->name();
    }

    public function description(): string
    {
        return $this->definition->description();
    }

    public function parametersSchema(): array
    {
        $schema = $this->definition->parametersSchema();
        if (($schema['type'] ?? null) !== 'object') {
            return ['type' => 'object', 'properties' => $schema['properties'] ?? []];
        }

        return $schema;
    }

    public function permission(): ?string
    {
        return $this->definition->permission();
    }

    public function surfaces(): array
    {
        return $this->definition->surfaces();
    }

    public function invoke(array $args, AiActor $actor): AiToolResult
    {
        if (!$this->definition->enabled()) {
            return AiToolResult::fail('Tool is disabled');
        }

        return match ($this->definition->handlerType()) {
            'static' => $this->invokeStatic($args),
            'http' => $this->invokeHttp($args, $actor),
            default => AiToolResult::fail('Unknown handler type'),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function invokeStatic(array $args): AiToolResult
    {
        $cfg = $this->definition->config();
        $payload = $cfg['response'] ?? $cfg['data'] ?? ['ok' => true, 'args' => $args];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : ['text' => $payload];
        }
        if (!is_array($payload)) {
            $payload = ['value' => $payload];
        }
        if (!empty($cfg['echo_args'])) {
            $payload['args'] = $args;
        }

        return AiToolResult::ok($payload);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function invokeHttp(array $args, AiActor $actor): AiToolResult
    {
        $cfg = $this->definition->config();
        $url = trim((string) ($cfg['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return AiToolResult::fail('HTTP tool needs a valid http(s) URL in config.url');
        }

        $method = strtoupper((string) ($cfg['method'] ?? 'POST'));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH'], true)) {
            $method = 'POST';
        }

        $timeout = max(1, min(30, (int) ($cfg['timeout'] ?? 15)));
        $body = [
            'tool' => $this->definition->name(),
            'args' => $args,
            'actor' => [
                'type' => $actor->type,
                'id' => $actor->id,
                'surface' => $actor->surface,
            ],
        ];
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: GEREQ-AI-Tool/1.0',
        ];
        $extraHeaders = $cfg['headers'] ?? [];
        if (is_array($extraHeaders)) {
            foreach ($extraHeaders as $k => $v) {
                if (is_string($k) && (is_string($v) || is_numeric($v))) {
                    $headers[] = $k . ': ' . $v;
                }
            }
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return AiToolResult::fail('curl_init failed');
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        } else {
            $sep = str_contains($url, '?') ? '&' : '?';
            curl_setopt($ch, CURLOPT_URL, $url . $sep . http_build_query(['tool' => $this->definition->name()] + $args));
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            return AiToolResult::fail('HTTP tool request failed: ' . ($err !== '' ? $err : 'unknown'));
        }
        if ($status < 200 || $status >= 300) {
            return AiToolResult::fail('HTTP tool returned status ' . $status);
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return AiToolResult::ok($decoded);
        }

        return AiToolResult::ok(['raw' => mb_substr((string) $raw, 0, 4000)]);
    }
}

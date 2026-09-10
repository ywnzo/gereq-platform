<?php

declare(strict_types=1);

namespace Gereq\Debug;

/**
 * Append-only debug log of service flows (CarService / BikeService / iDoklad / documents).
 * Soubor: storage/logs/full-service.log
 */
final class FullServiceLogger
{
    private static ?string $path = null;

    private static ?string $requestId = null;

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $event, array $context = []): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $row = [
            'ts' => date('Y-m-d H:i:s'),
            'rid' => self::requestId(),
            'event' => $event,
            'ctx' => self::normalize($context),
        ];

        try {
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $line = json_encode([
                'ts' => date('Y-m-d H:i:s'),
                'rid' => self::requestId(),
                'event' => $event,
                'ctx' => ['error' => 'json_encode_failed'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"event":"encode_fail"}';
        }

        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        try {
            if (function_exists('base_path')) {
                self::$path = base_path('storage/logs/full-service.log');

                return self::$path;
            }
        } catch (\Throwable) {
            // fallback below
        }

        self::$path = dirname(__DIR__, 2) . '/storage/logs/full-service.log';

        return self::$path;
    }

    private static function requestId(): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }

        try {
            self::$requestId = bin2hex(random_bytes(4));
        } catch (\Throwable) {
            self::$requestId = substr(uniqid('', true), -8);
        }

        return self::$requestId;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function normalize(array $context): array
    {
        $out = [];
        foreach ($context as $k => $v) {
            $key = (string) $k;
            if (self::isSensitiveKey($key)) {
                $out[$key] = self::redact($v);
                continue;
            }
            if (is_string($v) && strlen($v) > 4000) {
                $out[$key] = substr($v, 0, 4000) . '…[' . strlen($v) . 'B]';
            } elseif (is_array($v)) {
                $out[$key] = self::normalizeArray($v, 0);
            } elseif ($v instanceof \Throwable) {
                $out[$key] = [
                    'class' => $v::class,
                    'message' => $v->getMessage(),
                    'file' => $v->getFile() . ':' . $v->getLine(),
                ];
            } elseif (is_object($v)) {
                $out[$key] = $v::class;
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function normalizeArray(array $value, int $depth): array
    {
        if ($depth > 4) {
            return ['_truncated' => true, 'count' => count($value)];
        }
        $out = [];
        $i = 0;
        foreach ($value as $k => $v) {
            if ($i++ > 60) {
                $out['…'] = (count($value) - 60) . ' more';
                break;
            }
            $key = (string) $k;
            if (self::isSensitiveKey($key)) {
                $out[$key] = self::redact($v);
                continue;
            }
            if (is_string($v) && strlen($v) > 1500) {
                $out[$key] = substr($v, 0, 1500) . '…[' . strlen($v) . 'B]';
            } elseif (is_array($v)) {
                $out[$key] = self::normalizeArray($v, $depth + 1);
            } elseif ($v instanceof \Throwable) {
                $out[$key] = [
                    'class' => $v::class,
                    'message' => $v->getMessage(),
                ];
            } elseif (is_object($v)) {
                $out[$key] = $v::class;
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $k = strtolower($key);

        return str_contains($k, 'secret')
            || str_contains($k, 'password')
            || str_contains($k, 'token')
            || str_contains($k, 'authorization')
            || $k === 'signature'
            || $k === 'client_secret'
            || $k === 'access_token'
            || $k === 'refresh_token';
    }

    private static function redact(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_string($value)) {
            return '[redacted:' . strlen($value) . 'B]';
        }
        if (is_array($value)) {
            return '[redacted:array:' . count($value) . ']';
        }

        return '[redacted]';
    }
}

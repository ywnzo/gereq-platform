<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application;

/**
 * Append-only file log všech PartsCatalog HTTP / provider callů.
 * Soubor: storage/logs/partscatalog.log
 */
final class CatalogCallLogger
{
    private static ?string $path = null;

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
            'event' => $event,
            'ctx' => self::normalize($context),
        ];

        try {
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $line = json_encode([
                'ts' => date('Y-m-d H:i:s'),
                'event' => $event,
                'ctx' => ['error' => 'json_encode_failed'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"event":"encode_fail"}';
        }

        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        $base = dirname(__DIR__, 3); // modules/PartsCatalog/Application → project root
        // Application is modules/PartsCatalog/Application → dirname 3 = project root? 
        // __DIR__ = .../modules/PartsCatalog/Application
        // dirname 1 = PartsCatalog, 2 = modules, 3 = project root. Yes.
        self::$path = $base . '/storage/logs/partscatalog.log';

        return self::$path;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function normalize(array $context): array
    {
        $out = [];
        foreach ($context as $k => $v) {
            if (is_string($v) && strlen($v) > 2000) {
                $out[$k] = substr($v, 0, 2000) . '…[' . strlen($v) . 'B]';
            } elseif (is_array($v)) {
                $out[$k] = self::normalizeArray($v, 0);
            } else {
                $out[$k] = $v;
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
        if ($depth > 3) {
            return ['_truncated' => true, 'count' => count($value)];
        }
        $out = [];
        $i = 0;
        foreach ($value as $k => $v) {
            if ($i++ > 40) {
                $out['…'] = count($value) - 40 . ' more';
                break;
            }
            if (is_string($v) && strlen($v) > 800) {
                $out[$k] = substr($v, 0, 800) . '…[' . strlen($v) . 'B]';
            } elseif (is_array($v)) {
                $out[$k] = self::normalizeArray($v, $depth + 1);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}

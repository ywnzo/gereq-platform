<?php

declare(strict_types=1);

namespace Modules\Translate\Application;

/**
 * Live debug do souboru — viditelné i když prohlížeč čeká / timeoutne.
 */
final class TranslateDebug
{
    public static function log(string $step, mixed $data = null): void
    {
        $payload = [
            't' => date('H:i:s'),
            'step' => $step,
            'data' => $data,
        ];
        bdump($payload, 'translate.' . $step);

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $step;
        if ($data !== null) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $line .= ' ' . ($encoded !== false ? $encoded : print_r($data, true));
        }
        $line .= PHP_EOL;

        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(self::logPath(), $line, FILE_APPEND | LOCK_EX);
        error_log('[translate] ' . $step . ($data !== null ? ' ' . mb_substr((string) json_encode($data), 0, 200) : ''));
    }

    public static function logPath(): string
    {
        return self::logDir() . '/translate.log';
    }

    public static function logDir(): string
    {
        // Application -> Translate -> modules -> project root
        $root = dirname(__DIR__, 3);
        $preferred = $root . '/storage/logs';
        if (is_dir($preferred) || @mkdir($preferred, 0775, true)) {
            if (is_writable($preferred)) {
                return $preferred;
            }
        }
        $fallback = $root . '/storage/cache/translate';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }

        return is_writable($fallback) ? $fallback : sys_get_temp_dir();
    }

    /** @return list<string> */
    public static function tail(int $lines = 40): array
    {
        $path = self::logPath();
        if (!is_file($path)) {
            return [];
        }
        $content = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($content)) {
            return [];
        }

        return array_values(array_slice($content, -$lines));
    }

    public static function clear(): void
    {
        $path = self::logPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

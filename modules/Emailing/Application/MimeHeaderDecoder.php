<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

/**
 * Dekódování MIME encoded-word hlaviček (=?UTF-8?B?...?=).
 */
final class MimeHeaderDecoder
{
    public static function decode(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = trim($value);
        if (str_contains($value, '=?')) {
            if (function_exists('iconv_mime_decode')) {
                $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
                if (is_string($decoded) && $decoded !== '') {
                    $value = $decoded;
                }
            } elseif (function_exists('mb_decode_mimeheader')) {
                $prev = mb_internal_encoding();
                mb_internal_encoding('UTF-8');
                $decoded = @mb_decode_mimeheader($value);
                mb_internal_encoding($prev ?: 'UTF-8');
                if (is_string($decoded) && $decoded !== '') {
                    $value = $decoded;
                }
            } else {
                $value = (string) preg_replace_callback(
                    '/=\?([^?]+)\?([BQbq])\?([^?]*)\?=/',
                    static function (array $m): string {
                        $charset = $m[1];
                        $encoding = strtoupper($m[2]);
                        $text = $m[3];
                        $raw = $encoding === 'B'
                            ? (base64_decode(str_replace(["\r", "\n", ' '], '', $text), true) ?: '')
                            : quoted_printable_decode(str_replace('_', ' ', $text));
                        if ($raw === '') {
                            return $m[0];
                        }
                        $converted = @iconv($charset, 'UTF-8//IGNORE', $raw);
                        if ($converted === false) {
                            $converted = @mb_convert_encoding($raw, 'UTF-8', $charset);
                        }

                        return is_string($converted) ? $converted : $raw;
                    },
                    $value,
                );
            }
        }

        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = trim(substr($value, 1, -1));
        }

        return $value;
    }
}

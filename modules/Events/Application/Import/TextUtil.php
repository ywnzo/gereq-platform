<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

final class TextUtil
{
    public static function decode(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    public static function plainFromHtml(string $html, int $maxLen = 500): string
    {
        $plain = self::decode(strip_tags($html));
        if (mb_strlen($plain) <= $maxLen) {
            return $plain;
        }

        return rtrim(mb_substr($plain, 0, $maxLen - 1)) . '…';
    }

    public static function sanitizeHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        $html = trim($html);
        if ($html === '') {
            return null;
        }
        // Keep basic markup from source descriptions.
        $html = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/javascript:/i', '', $html) ?? $html;

        return $html !== '' ? $html : null;
    }
}

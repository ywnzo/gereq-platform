<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Gereq\Security\HtmlSanitizer;

final class ContentSecurity
{
    public static function link(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
            return '';
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1
            && preg_match('/^(https?:\/\/|mailto:|tel:)/i', $value) !== 1) {
            return '';
        }

        return $value;
    }

    /** @param array<string, string> $variables */
    public static function expandHtml(string $source, array $variables): string
    {
        $expanded = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static fn (array $match): string => array_key_exists($match[1], $variables)
                ? htmlspecialchars($variables[$match[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : $match[0],
            $source,
        );

        return (new HtmlSanitizer())->sanitize($expanded);
    }
}

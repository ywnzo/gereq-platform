<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

final class TextLimits
{
    private const FIELDS = ['all', 'quote', 'body', 'html', 'label', 'caption', 'left', 'right', 'placeholder'];
    private const TYPES = [
        'text' => ['body'], 'html' => ['html'], 'quote' => ['quote', 'author', 'role'],
        'badge' => ['label'], 'cta' => ['label'], 'image' => ['caption'], 'video' => ['caption'], 'table' => ['caption'],
        'columns' => ['left', 'right'], 'form_field' => ['label', 'placeholder'],
    ];

    /** @return array<string, array{max: int, suffix: string}> */
    public static function normalize(mixed $raw): array
    {
        $result = [];
        if (!is_array($raw)) {
            return $result;
        }
        foreach (self::FIELDS as $field) {
            $rule = $raw[$field] ?? null;
            if (!is_array($rule)) {
                continue;
            }
            $max = is_scalar($rule['max'] ?? null) ? max(0, min(100000, (int) $rule['max'])) : 0;
            $suffix = is_string($rule['suffix'] ?? null) ? mb_substr($rule['suffix'], 0, 500) : '…';
            $result[$field] = ['max' => $max, 'suffix' => $suffix];
        }
        return $result;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function apply(string $type, array $payload): array
    {
        $rules = self::normalize($payload['text_limits'] ?? []);
        foreach (self::TYPES[$type] ?? [] as $field) {
            $rule = $type === 'quote' ? ($rules['all'] ?? $rules['quote'] ?? null) : ($rules[$field] ?? null);
            if ($rule === null || !is_string($payload[$field] ?? null)) {
                continue;
            }
            $rich = in_array($field, ['body', 'html', 'left', 'right'], true)
                || ($field === 'caption' && $type === 'table') || ($field === 'label' && $type === 'cta');
            $payload[$field] = self::truncate($payload[$field], $rule['max'], $rule['suffix'], $rich);
        }
        return $payload;
    }

    public static function truncate(string $value, int $max, string $suffix = '…', bool $html = false): string
    {
        if ($max <= 0) {
            return $value;
        }
        if (!$html) {
            return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max)) . $suffix : $value;
        }
        preg_match_all('~<!--[\s\S]*?-->|<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>|[^<]+|<~u', $value, $matches);
        $tokens = $matches[0];
        $length = 0;
        foreach ($tokens as $token) {
            if (!str_starts_with($token, '<') || strlen($token) === 1) {
                $length += count(self::units($token));
            }
        }
        if ($length <= $max) {
            return $value;
        }
        $stack = [];
        $out = '';
        foreach ($tokens as $token) {
            if ($max === 0) {
                break;
            }
            if (str_starts_with($token, '<') && strlen($token) > 1) {
                $out .= $token;
                if (preg_match('~^<\s*(/?)\s*([a-z][\w:-]*)~i', $token, $tag) === 1) {
                    $name = strtolower($tag[2]);
                    if ($tag[1] !== '') {
                        $index = array_search($name, array_reverse($stack, true), true);
                        if ($index !== false) {
                            array_splice($stack, $index);
                        }
                    } elseif (!in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true) && preg_match('~/\s*>$~', $token) !== 1) {
                        $stack[] = $name;
                    }
                }
            } else {
                $chars = self::units($token);
                $out .= implode('', array_slice($chars, 0, $max));
                $max -= min($max, count($chars));
            }
        }
        return rtrim($out) . htmlspecialchars($suffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . implode('', array_map(static fn (string $tag): string => '</' . $tag . '>', array_reverse($stack)));
    }

    /** @return list<string> */
    private static function units(string $value): array
    {
        preg_match_all('~&(?:#\d+|#x[\da-f]+|[a-z][\da-z]+);|[\s\S]~iu', $value, $matches);
        return $matches[0];
    }
}

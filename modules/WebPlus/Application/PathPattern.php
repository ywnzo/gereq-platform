<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application;

use Modules\WebPlus\Domain\WebMeta;

/** Match `/nabidky/{slug}` templates and interpolate `{title}` placeholders. */
final class PathPattern
{
    public static function isTemplate(string $path): bool
    {
        return str_contains($path, '{') && str_contains($path, '}');
    }

    /**
     * @return array<string, string>|null named params when the path matches
     */
    public static function match(string $template, string $path): ?array
    {
        $template = WebMeta::normalizePath($template);
        $path = WebMeta::normalizePath($path);
        if (!self::isTemplate($template)) {
            return $template === $path ? [] : null;
        }

        $names = [];
        $quoted = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $m) use (&$names): string {
                $names[] = $m[1];

                return '___VAR_' . (count($names) - 1) . '___';
            },
            $template,
        );
        if (!is_string($quoted)) {
            return null;
        }

        $regex = preg_quote($quoted, '#');
        foreach ($names as $i => $name) {
            $regex = str_replace('___VAR_' . $i . '___', '(?P<' . $name . '>[^/]+)', $regex);
        }

        if (preg_match('#^' . $regex . '$#u', $path, $m) !== 1) {
            return null;
        }

        $params = [];
        foreach ($names as $name) {
            $value = $m[$name] ?? '';
            if ($value === '') {
                return null;
            }
            $params[$name] = rawurldecode((string) $value);
        }

        return $params;
    }

    /** Higher score = more specific (static segments beat wildcards). */
    public static function specificity(string $template): int
    {
        $parts = explode('/', trim(WebMeta::normalizePath($template), '/'));
        $score = 0;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $score += preg_match('/^\{[A-Za-z_][A-Za-z0-9_]*\}$/', $part) === 1 ? 1 : 10;
        }

        return $score;
    }

    /**
     * @param array<string, string|int|float|null> $vars
     */
    public static function interpolate(string $text, array $vars): string
    {
        if ($vars === [] || !str_contains($text, '{')) {
            return $text;
        }

        foreach ($vars as $key => $value) {
            if ($value === null) {
                continue;
            }
            $text = str_replace('{' . $key . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Path segments as `{page}`, `{x}`, `{y}` plus `{1}`, `{2}`… for generic templates.
     *
     * @return array<string, string>
     */
    public static function segmentVars(string $path): array
    {
        $parts = array_values(array_filter(
            explode('/', trim(WebMeta::normalizePath($path), '/')),
            static fn (string $p): bool => $p !== '',
        ));
        $vars = [];
        $named = ['page', 'x', 'y', 'z'];
        foreach ($parts as $i => $part) {
            $vars[(string) ($i + 1)] = $part;
            if (isset($named[$i])) {
                $vars[$named[$i]] = $part;
            }
        }
        if (isset($parts[1]) && $parts[0] === 'nabidky') {
            $vars['slug'] = $parts[1];
        }

        return $vars;
    }
}

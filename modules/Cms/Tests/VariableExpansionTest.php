<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Lightweight coverage for {{ path }} interpolation used by CMS preview.
 */
final class VariableExpansionTest extends TestCase
{
    public function testPlaceholderExpansion(): void
    {
        $vars = [
            'page.title' => 'About',
            'brand.name' => 'GEREQ',
        ];

        self::assertSame(
            'About — GEREQ',
            self::expand('{{ page.title }} — {{ brand.name }}', $vars),
        );
        self::assertSame(
            'Hello {{ missing.path }}',
            self::expand('Hello {{ missing.path }}', $vars),
        );
    }

    public function testNestedPayloadExpansion(): void
    {
        $vars = ['page.title' => 'Home'];
        $payload = [
            'text' => 'Welcome to {{ page.title }}',
            'images' => [
                ['alt' => '{{ page.title }} hero'],
            ],
        ];

        $expanded = self::expandPayload($payload, $vars);

        self::assertSame('Welcome to Home', $expanded['text']);
        self::assertIsArray($expanded['images']);
        self::assertSame('Home hero', $expanded['images'][0]['alt']);
    }

    /** @param array<string, string> $vars */
    private static function expand(string $text, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $match) use ($vars): string {
                $path = $match[1];

                return array_key_exists($path, $vars) ? $vars[$path] : $match[0];
            },
            $text,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $vars
     * @return array<string, mixed>
     */
    private static function expandPayload(array $payload, array $vars): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $payload[$key] = self::expand($value, $vars);
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $nested */
                $nested = $value;
                $payload[$key] = self::expandPayload($nested, $vars);
            }
        }

        return $payload;
    }
}

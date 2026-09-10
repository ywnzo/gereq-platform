<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\WebPlus;

use Modules\WebPlus\Application\MetaHtmlRenderer;
use PHPUnit\Framework\TestCase;

final class MetaHtmlRendererTest extends TestCase
{
    public function testHeadIncludesTitleOgAndJsonLd(): void
    {
        $html = (new MetaHtmlRenderer())->head([
            'site_name' => 'Reality',
            'seo' => ['title' => 'Chalupa Rýmařov'],
            'og' => ['title' => 'Chalupa Rýmařov'],
            'tags' => [
                ['kind' => 'title', 'key' => 'title', 'value' => 'Chalupa Rýmařov'],
                ['kind' => 'name', 'key' => 'description', 'value' => 'Chalupa v Jeseníkách'],
                ['kind' => 'property', 'key' => 'og:title', 'value' => 'Chalupa Rýmařov'],
                ['kind' => 'property', 'key' => 'og:image', 'value' => 'https://example.test/cover.jpg'],
                ['kind' => 'name', 'key' => 'geo.placename', 'value' => 'Rýmařov'],
            ],
            'json_ld' => [
                ['@type' => 'RealEstateListing', 'name' => 'Chalupa Rýmařov'],
            ],
        ]);

        self::assertStringContainsString('<title>Chalupa Rýmařov · Reality</title>', $html);
        self::assertStringContainsString('property="og:title"', $html);
        self::assertStringContainsString('name="geo.placename"', $html);
        self::assertStringContainsString('RealEstateListing', $html);
    }

    public function testDocumentIsStandaloneHtml(): void
    {
        $html = (new MetaHtmlRenderer())->document([
            'seo' => ['title' => 'Test', 'description' => 'Popis', 'canonical_url' => 'https://ex.test/nabidky/x'],
            'og' => ['title' => 'Test', 'image' => 'https://ex.test/a.jpg'],
            'tags' => [],
            'json_ld' => [],
        ]);

        self::assertStringContainsString('<!doctype html>', $html);
        self::assertStringContainsString('<h1>Test</h1>', $html);
        self::assertStringContainsString('<img src="https://ex.test/a.jpg"', $html);
    }
}

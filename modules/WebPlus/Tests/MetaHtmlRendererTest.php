<?php

declare(strict_types=1);

namespace Modules\WebPlus\Tests;

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

    public function testInjectIntoSpaReplacesShellMetaAndPreloadsOgImage(): void
    {
        $shell = <<<'HTML'
<!doctype html>
<html lang="cs">
  <head>
    <meta charset="UTF-8" />
    <title>GEREQ CMS — storefront</title>
    <meta name="description" content="Generic shell" />
    <link rel="icon" href="data:," />
  </head>
  <body>
    <div id="app"></div>
  </body>
</html>
HTML;

        $html = (new MetaHtmlRenderer())->injectIntoSpa($shell, [
            'site_name' => 'Reality',
            'seo' => ['title' => 'Domů'],
            'og' => ['image' => 'https://example.test/hero.avif'],
            'tags' => [
                ['kind' => 'title', 'key' => 'title', 'value' => 'Domů'],
                ['kind' => 'name', 'key' => 'description', 'value' => 'Úvodní stránka'],
                ['kind' => 'property', 'key' => 'og:image', 'value' => 'https://example.test/hero.avif'],
            ],
            'json_ld' => [],
        ]);

        self::assertStringNotContainsString('GEREQ CMS — storefront', $html);
        self::assertStringNotContainsString('Generic shell', $html);
        self::assertStringContainsString('<title data-webplus="1">Domů · Reality</title>', $html);
        self::assertStringContainsString('name="description"', $html);
        self::assertStringContainsString('Úvodní stránka', $html);
        self::assertStringContainsString('rel="preload" as="image"', $html);
        self::assertStringContainsString('https://example.test/hero.avif', $html);
        self::assertStringContainsString('data-gereq-webplus-spa="1"', $html);
        self::assertStringContainsString('<div id="app"></div>', $html);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Modules\Cms\Application\ContentSecurity;
use Modules\Cms\Application\CmsSsgHtmlRenderer;
use PHPUnit\Framework\TestCase;

final class ContentSecurityTest extends TestCase
{
    public function testExecutableUrlsAreRejected(): void
    {
        foreach (['javascript:alert(1)', "java\nscript:alert(1)", 'data:text/html,test', 'vbscript:test', '\\evil.test'] as $url) {
            self::assertSame('', ContentSecurity::link($url));
        }
        foreach (['https://example.test/path', '/page', '#section', 'mailto:a@example.test', 'tel:+420123456789'] as $url) {
            self::assertSame($url, ContentSecurity::link($url));
        }
    }

    public function testHtmlVariablesAreTextAndCannotIntroduceExecutableUrls(): void
    {
        $html = ContentSecurity::expandHtml('<p>{{ page.title }}</p><a href="{{ page.url }}">Link</a>', [
            'page.title' => '<img src=x onerror="bad()">',
            'page.url' => 'javascript:bad()',
        ]);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    public function testStaticOutputRejectsExecutableCtaUrlsAndHtml(): void
    {
        $html = (new CmsSsgHtmlRenderer())->renderFragment([
            ['type' => 'cta', 'payload' => ['label' => 'Link', 'url' => 'javascript:bad()']],
            ['type' => 'html', 'payload' => ['html' => '<p onclick="bad()">Safe</p>']],
        ]);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringContainsString('Safe', $html);
    }
    public function testStaticLegacyFieldsAreSanitizedAtTheOutputBoundary(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        $attack = '<img src=x onerror="bad()"><p onclick="bad()">Safe</p>';
        foreach ([
            ['type' => 'heading', 'payload' => ['text' => $attack]],
            ['type' => 'columns', 'payload' => ['left' => $attack, 'right' => $attack]],
            ['type' => 'table', 'payload' => ['html' => $attack, 'caption' => $attack]],
            ['type' => 'video', 'payload' => ['url' => 'javascript:bad()']],
        ] as $block) {
            $output = $renderer->renderFragment([$block]);
            self::assertDoesNotMatchRegularExpression('/onerror|onclick|javascript:/i', $output);
        }
        self::assertDoesNotMatchRegularExpression('/onerror|onclick/i', $renderer->render(['content_mode' => 'raw', 'body' => $attack]));
    }
}

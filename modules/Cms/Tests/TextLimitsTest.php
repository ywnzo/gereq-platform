<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Testing\TestCase;
use Modules\Cms\Application\TextLimits;
use Modules\Cms\Application\BlockDesignNormalizer;
use Modules\Cms\Application\CmsSsgHtmlRenderer;

final class TextLimitsTest extends TestCase
{
    public function testLimitsPreserveUnicodeMarkupAndEscapeSuffix(): void
    {
        self::assertSame('Příliš 😀 číst dále', TextLimits::truncate('Příliš 😀 dlouhý', 8, ' číst dále'));
        self::assertSame('<p>Ahoj <strong>sv…</strong></p>', TextLimits::truncate('<p>Ahoj <strong>světe</strong>!</p>', 7, '…', true));
        self::assertSame('<p>A &amp; B 😀 &lt;více&gt;</p>', TextLimits::truncate('<p>A &amp; B 😀!</p>', 7, ' <více>', true));
        self::assertSame('abc', TextLimits::truncate('abcdef', 3, ''));
        self::assertSame('abc', TextLimits::truncate('abc', 3, '…'));
        self::assertSame('abcdef', TextLimits::truncate('abcdef', 0));
    }

    public function testSnapshotUsesTheSameDisplayLimitInsideRepeatedItems(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        $html = $renderer->renderFragment([['type' => 'dynamic', 'payload' => [
            'source' => 'reviews', 'resolved_items' => [[['type' => 'text', 'payload' => [
                'body' => '<p>Skvělá spolupráce</p>',
                'text_limits' => ['body' => ['max' => 6, 'suffix' => ' číst dále']],
            ]]]],
        ]]]);
        self::assertStringContainsString('<p>Skvělá číst dále</p>', $html);
        self::assertStringNotContainsString('spolupráce', $html);
    }

    public function testNormalizationAndRenderingDoNotChangeSourceOrHeadings(): void
    {
        $source = ['body' => '<p>abcdef</p>', 'text_limits' => ['body' => ['max' => 3, 'suffix' => ' číst dále']]];
        $saved = BlockDesignNormalizer::merge($source, $source);
        self::assertSame($source['text_limits'], $saved['text_limits']);
        self::assertSame('<p>abc číst dále</p>', TextLimits::apply('text', $saved)['body']);
        self::assertSame('<p>abcdef</p>', $saved['body']);
        self::assertSame('abcdef', TextLimits::apply('heading', ['text' => 'abcdef', 'text_limits' => ['text' => ['max' => 2]]])['text']);
        self::assertSame([], TextLimits::normalize(['body' => 'invalid', 'url' => ['max' => 3]]));
        self::assertSame(100000, TextLimits::normalize(['body' => ['max' => 999999]])['body']['max']);
    }
    public function testQuotesShareOneLimitAcrossAllParts(): void
    {
        $payload = ['quote' => 'Quote text', 'author' => 'Author', 'role' => 'Role', 'text_limits' => [
            'all' => ['max' => 3, 'suffix' => '…'], 'author' => ['max' => 1],
        ]];
        $result = TextLimits::apply('quote', $payload);
        self::assertSame('Quo…', $result['quote']);
        self::assertSame('Aut…', $result['author']);
        self::assertSame('Rol…', $result['role']);
    }
}

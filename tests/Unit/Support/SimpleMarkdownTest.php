<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Support;

use Gereq\Support\SimpleMarkdown;
use PHPUnit\Framework\TestCase;

final class SimpleMarkdownTest extends TestCase
{
    public function testRendersHeadingsBoldAndLists(): void
    {
        $raw = <<<'MD'
Nabízíme **rodinný dům** ve **Vítkov – Centrum**.

## Dispozice a technické údaje
Užitná plocha **118 m²**, pokoje **4**.

## Co je součástí
- terasa
- garáž
MD;

        $html = SimpleMarkdown::toHtml($raw);

        self::assertStringContainsString('<strong>rodinný dům</strong>', $html);
        self::assertStringContainsString('<h3>Dispozice a technické údaje</h3>', $html);
        self::assertStringContainsString('<li>terasa</li>', $html);
        self::assertStringContainsString('<li>garáž</li>', $html);
        self::assertStringNotContainsString('**', $html);
        self::assertStringNotContainsString('##', $html);
    }

    public function testEscapesHtml(): void
    {
        $html = SimpleMarkdown::toHtml('Pozor <script>alert(1)</script> a **tučné**');

        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('<strong>tučné</strong>', $html);
    }

    public function testEmptyReturnsEmptyString(): void
    {
        self::assertSame('', SimpleMarkdown::toHtml(null));
        self::assertSame('', SimpleMarkdown::toHtml('   '));
    }
}

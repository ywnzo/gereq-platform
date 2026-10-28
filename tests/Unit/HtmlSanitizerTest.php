<?php
declare(strict_types=1);

namespace Gereq\Tests\Unit;
use Gereq\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testPaletteColorVariablesSurviveWithoutAllowingArbitraryCssVariables(): void
    {
        $sanitizer = new HtmlSanitizer();
        foreach (['--color-paper', '--color-surface', '--color-header', '--color-brand-700', '--color-secondary', '--color-accent'] as $variable) {
            $clean = $sanitizer->sanitize('<span style="color: var(' . $variable . '); background-color: var(' . $variable . ')">Text</span>');
            self::assertStringContainsString('color: var(' . $variable . ')', $clean);
            self::assertStringContainsString('background-color: var(' . $variable . ')', $clean);
        }
        self::assertStringNotContainsString('var(', $sanitizer->sanitize('<span style="color: var(--untrusted)">Text</span>'));
        self::assertStringNotContainsString('var(', $sanitizer->sanitize('<span style="color: var(--color-paper, red)">Text</span>'));
    }

    public function testTableMarkupSurvives(): void
    {
        $html = '<table><colgroup><col style="width: 200px"></colgroup>'
            . '<thead><tr><th colspan="2">H</th></tr></thead>'
            . '<tbody><tr><td rowspan="2">A</td><td>B</td></tr></tbody></table>';
        self::assertSame($html, (new HtmlSanitizer())->sanitize($html));
    }
    public function testDangerousTableContentIsStripped(): void
    {
        $clean = (new HtmlSanitizer())->sanitize(
            '<td onclick="x()" style="position:fixed">A</td><script>alert(1)</script>',
        );
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('position', $clean);
        self::assertStringNotContainsString('script', $clean);
        self::assertStringContainsString('A', $clean);
    }
    public function testUnsupportedWrappersCannotHideExecutableDescendants(): void
    {
        $sanitizer = new HtmlSanitizer();
        foreach ([
            '<section><article><img src=x onerror="alert(1)"></article></section>',
            '<section><p onclick="alert(1)">Safe</p></section>',
            '<article><a href="javascript:alert(1)">Link</a></article>',
            '<section><script>alert(1)</script></section>',
            '<div><svg><a onload="alert(1)">Bad</a></svg></div>',
        ] as $source) {
            $clean = $sanitizer->sanitize($source);
            self::assertDoesNotMatchRegularExpression('/onerror|onclick|onload|javascript:|<script|<svg/i', $clean);
            self::assertSame($clean, $sanitizer->sanitize($clean));
        }
    }

    public function testUnwrappedSafeDescendantsKeepTheirContentAndLoseUnsafeAttributes(): void
    {
        $clean = (new HtmlSanitizer())->sanitize('<section><article><p onclick="bad()"><strong>Safe</strong></p></article></section>');
        self::assertSame('<p><strong>Safe</strong></p>', $clean);
    }
}

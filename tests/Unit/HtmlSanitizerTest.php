<?php
declare(strict_types=1);

namespace Gereq\Tests\Unit;
use Gereq\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
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
}

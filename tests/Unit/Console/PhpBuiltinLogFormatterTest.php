<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Console;

use Gereq\Console\Serve\PhpBuiltinLogFormatter;
use PHPUnit\Framework\TestCase;

final class PhpBuiltinLogFormatterTest extends TestCase
{
    private PhpBuiltinLogFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new PhpBuiltinLogFormatter();
    }

    public function testParseAccessLine(): void
    {
        $line = '[Sat Oct 17 08:15:42 2026] 127.0.0.1:54321 [200]: GET /admin/users';
        $entry = $this->formatter->parse($line);

        self::assertNotNull($entry);
        self::assertTrue($entry->access);
        self::assertFalse($entry->noise);
        self::assertSame('GET', $entry->method);
        self::assertSame(200, $entry->status);
        self::assertSame('/admin/users', $entry->path);
        self::assertSame('08:15:42', $entry->time);
        self::assertNull($entry->payload);
    }

    public function testParsePayload(): void
    {
        $line = '[Sat Oct 17 08:15:42 2026] 127.0.0.1:54321 [302]: POST /admin/settings | foo=1&bar=2';
        $entry = $this->formatter->parse($line);

        self::assertNotNull($entry);
        self::assertSame('POST', $entry->method);
        self::assertSame('/admin/settings', $entry->path);
        self::assertSame('foo=1&bar=2', $entry->payload);
    }

    public function testParseStripsQueryString(): void
    {
        $line = '[Sat Oct 17 08:15:42 2026] 127.0.0.1:54321 [302]: POST /admin/login?next=/x';
        $entry = $this->formatter->parse($line);

        self::assertNotNull($entry);
        self::assertSame('POST', $entry->method);
        self::assertSame('/admin/login', $entry->path);
        self::assertSame(302, $entry->status);
    }

    public function testParseNoiseAcceptedAndClosing(): void
    {
        $accepted = $this->formatter->parse('[Sat Oct 17 08:15:42 2026] 127.0.0.1:54321 Accepted');
        $closing = $this->formatter->parse('[Sat Oct 17 08:15:42 2026] 127.0.0.1:54321 Closing');

        self::assertNotNull($accepted);
        self::assertTrue($accepted->noise);
        self::assertNotNull($closing);
        self::assertTrue($closing->noise);
        self::assertNull($this->formatter->format('[Sat Oct 17 08:15:42 2026] 127.0.0.1:54321 Accepted'));
        self::assertNull($this->formatter->format('[Sat Oct 17 08:15:42 2026] 127.0.0.1:54321 Closing'));
    }

    public function testFormatUsesDistinctMethodColours(): void
    {
        $get = $this->formatter->format('[Sat Oct 17 08:15:42 2026] 127.0.0.1:1 [200]: GET /admin');
        $post = $this->formatter->format('[Sat Oct 17 08:15:42 2026] 127.0.0.1:1 [201]: POST /api/v1/x');
        $put = $this->formatter->format('[Sat Oct 17 08:15:42 2026] 127.0.0.1:1 [200]: PUT /api/v1/x');
        $del = $this->formatter->format('[Sat Oct 17 08:15:42 2026] 127.0.0.1:1 [204]: DELETE /api/v1/x');

        self::assertIsString($get);
        self::assertStringContainsString('fg=cyan', $get);
        self::assertStringNotContainsString('fg=magenta', $get);

        self::assertIsString($post);
        self::assertStringContainsString('fg=magenta;options=bold', $post);
        self::assertStringNotContainsString('fg=cyan>POST', $post);

        self::assertIsString($put);
        self::assertStringContainsString('fg=blue;options=bold', $put);

        self::assertIsString($del);
        self::assertStringContainsString('fg=red;options=bold', $del);
    }

    public function testAnsiMethodColoursDiffer(): void
    {
        $get = $this->formatter->parse('[Sat Oct 17 08:15:42 2026] 127.0.0.1:1 [200]: GET /a');
        $post = $this->formatter->parse('[Sat Oct 17 08:15:42 2026] 127.0.0.1:1 [302]: POST /a | x=1');
        self::assertNotNull($get);
        self::assertNotNull($post);

        $getRows = $this->formatter->formatAnsiRows($get);
        $postRows = $this->formatter->formatAnsiRows($post);
        self::assertNotNull($getRows);
        self::assertNotNull($postRows);
        self::assertStringContainsString("\033[36m", $getRows[0]);
        self::assertStringContainsString("\033[95;1m", $postRows[0]);
        self::assertStringContainsString('x=1', $postRows[0]);
    }

    public function testPayloadPreviewAndExpand(): void
    {
        $long = str_repeat('a', 250);
        $line = '[Sat Oct 17 08:15:42 2026] 127.0.0.1:1 [200]: POST /x | ' . $long;
        $entry = $this->formatter->parse($line);
        self::assertNotNull($entry);

        $collapsed = $this->formatter->formatAnsiRows($entry, 1, expandPayload: false);
        self::assertNotNull($collapsed);
        self::assertCount(1, $collapsed);
        self::assertStringContainsString('…', $collapsed[0]);
        self::assertStringNotContainsString(str_repeat('a', 250), $collapsed[0]);

        $expanded = $this->formatter->formatAnsiRows($entry, 1, expandPayload: true, width: 80);
        self::assertNotNull($expanded);
        self::assertGreaterThan(1, count($expanded));
        self::assertStringContainsString('payload', $expanded[0]);
    }

    public function testFormatHidesEmptyAndKeepsUnknownLines(): void
    {
        self::assertNull($this->formatter->format(''));
        $sys = $this->formatter->format('[Sat Oct 17 08:15:42 2026] PHP Notice: something');
        self::assertIsString($sys);
        self::assertStringContainsString('fg=gray', $sys);
        self::assertStringContainsString('PHP Notice', $sys);
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Console;

use Gereq\Console\Serve\PhpBuiltinLogCoalescer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\Output;

final class PhpBuiltinLogCoalescerTest extends TestCase
{
    public function testCollapsesIdenticalAccessLinesWithCounter(): void
    {
        $out = new class () extends Output {
            /** @var list<string> */
            public array $chunks = [];

            protected function doWrite(string $message, bool $newline): void
            {
                $this->chunks[] = $message . ($newline ? "\n" : '');
            }
        };

        $coalescer = new PhpBuiltinLogCoalescer();
        $line = '[Sat Oct 17 08:26:51 2026] 127.0.0.1:1 [200]: GET /build/watch.json';
        $coalescer->push($line, $out);
        $coalescer->push($line, $out);
        $coalescer->push($line, $out);
        $coalescer->flush($out);

        $joined = implode('', $out->chunks);
        self::assertStringContainsString('/build/watch.json', $joined);
        self::assertStringContainsString('3×', $joined);
        self::assertStringContainsString("\r\033[2K", $joined);
        self::assertMatchesRegularExpression('/3×(?:\n)?$/', $joined);
    }

    public function testDifferentPathEndsPreviousGroup(): void
    {
        $out = new BufferedOutput();
        $coalescer = new PhpBuiltinLogCoalescer();
        $a = '[Sat Oct 17 08:26:51 2026] 127.0.0.1:1 [200]: GET /build/watch.json';
        $b = '[Sat Oct 17 08:26:52 2026] 127.0.0.1:1 [200]: GET /admin';
        $coalescer->push($a, $out);
        $coalescer->push($a, $out);
        $coalescer->push($b, $out);
        $coalescer->flush($out);

        $text = $out->fetch();
        self::assertStringContainsString('2×', $text);
        self::assertStringContainsString('/admin', $text);
    }

    public function testHidesAcceptedNoise(): void
    {
        $out = new BufferedOutput();
        $coalescer = new PhpBuiltinLogCoalescer();
        $coalescer->push('[Sat Oct 17 08:26:51 2026] 127.0.0.1:1 Accepted', $out);
        $coalescer->flush($out);
        self::assertSame('', $out->fetch());
    }
}

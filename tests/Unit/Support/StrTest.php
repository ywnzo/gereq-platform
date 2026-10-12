<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Support;

use Gereq\Support\Str;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase
{
    public function testSlugIsDeterministicForCzechDiacritics(): void
    {
        self::assertSame('letni-open-air-koncert', Str::slug('Letní open-air koncert'));
        self::assertSame('pribeh-z-ceska', Str::slug('Příběh z Česka'));
    }

    public function testSlugCollapsesNonAlphanumeric(): void
    {
        self::assertSame('hello-world', Str::slug('Hello, World!'));
        self::assertSame('a-b', Str::slug('  a___b  '));
    }
}

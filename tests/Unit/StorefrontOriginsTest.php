<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Http\StorefrontOrigins;
use PHPUnit\Framework\TestCase;

final class StorefrontOriginsTest extends TestCase
{
    public function testNormalizesAndDeduplicatesCustomOrigins(): void
    {
        self::assertSame(
            ['https://shop.example.test', 'http://localhost:5173'],
            StorefrontOrigins::parseOverride("HTTPS://Shop.Example.Test/\nhttp://localhost:5173, https://shop.example.test"),
        );
    }

    public function testRejectsUnsafeOrMalformedOrigins(): void
    {
        foreach (['', '*', 'https://shop.test/path', 'https://user:pass@shop.test', 'https://shop.test?q=1', 'https://shop.test/#fragment', 'javascript:alert(1)', 'https://good.test,not-a-url'] as $value) {
            self::assertNull(StorefrontOrigins::parseOverride($value), $value);
        }
    }
}

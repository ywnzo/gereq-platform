<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Customers;

use Gereq\Customers\Domain\Customer;
use Gereq\Customers\Domain\CustomerTag;
use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase
{
    public function testArchiveRestoreAndStatusClamp(): void
    {
        $customer = new Customer('Jan Novák', 'jan@example.com');
        $customer->archive();
        self::assertTrue($customer->isArchived());

        $customer->restore('weird');
        self::assertSame('active', $customer->status());
        self::assertFalse($customer->isArchived());
    }

    public function testInitialsFromName(): void
    {
        $customer = new Customer('Jan Novák', 'jan@example.com');
        self::assertSame('JN', $customer->initials());

        $empty = new Customer('   ', 'x@example.com');
        self::assertSame('Z', $empty->initials());
    }

    public function testTagSlugify(): void
    {
        self::assertSame('hello-world', CustomerTag::slugify('Hello World'));
        self::assertSame('tag', CustomerTag::slugify('!!!'));
        self::assertSame('vip', CustomerTag::slugify('VIP'));
    }
}

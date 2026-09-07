<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Shop;

use Modules\Shop\Domain\Cart;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    public function testOpenConvertedAndAbandoned(): void
    {
        $cart = new Cart('cust-1', 'sess-1');
        self::assertTrue($cart->isOpen());
        self::assertSame('CZK', $cart->currency());

        $cart->setStatus('converted');
        self::assertFalse($cart->isOpen());

        $cart->setStatus('abandoned');
        self::assertFalse($cart->isOpen());

        $this->expectException(\InvalidArgumentException::class);
        $cart->setStatus('weird');
    }
}

<?php

declare(strict_types=1);

namespace Integrations\Comgate\Tests;

use Integrations\Comgate\Application\ComgateGateway;
use PHPUnit\Framework\TestCase;

final class PaymentOptionsTest extends TestCase
{
    public function testOptionsProduceExplicitGatewayRestrictions(): void
    {
        $gateway = (new \ReflectionClass(ComgateGateway::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($gateway, 'methodForType');

        self::assertSame('ALL', $method->invoke($gateway, 'online'));
        self::assertSame('CARD_ALL', $method->invoke($gateway, 'card'));
        self::assertSame('BANK_ONLY', $method->invoke($gateway, 'gateway_bank_transfer'));
        self::assertSame('APPLEPAY_REDIRECT', $method->invoke($gateway, 'apple_pay'));
        self::assertSame('GOOGLEPAY_REDIRECT', $method->invoke($gateway, 'google_pay'));
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($gateway, 'cod');
    }
}

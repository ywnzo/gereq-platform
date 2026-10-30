<?php

declare(strict_types=1);

namespace Integrations\GoPay\Tests;

use Integrations\GoPay\Application\GoPayGateway;
use Modules\Shop\Submodules\Payments\Domain\PaymentMethod;
use PHPUnit\Framework\TestCase;

final class PaymentOptionsTest extends TestCase
{
    public function testInstrumentUsesConfiguredOptionInsteadOfMethodCode(): void
    {
        $gateway = (new \ReflectionClass(GoPayGateway::class))->newInstanceWithoutConstructor();
        $instrument = new \ReflectionMethod($gateway, 'instrumentForMethod');

        self::assertSame('PAYMENT_CARD', $instrument->invoke($gateway, new PaymentMethod('APPLE-BANK', 'Card', 'card')));
        self::assertSame('BANK_ACCOUNT', $instrument->invoke($gateway, new PaymentMethod('CARD', 'Bank', 'gateway_bank_transfer')));
        self::assertNull($instrument->invoke($gateway, new PaymentMethod('BANK', 'Gateway', 'online')));
        self::assertNotContains('wallet', $gateway->paymentTypes());
    }
}

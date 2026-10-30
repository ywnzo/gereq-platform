<?php

declare(strict_types=1);

namespace Integrations\Stripe\Tests;

use Integrations\Stripe\Application\StripeGateway;
use Modules\Shop\Submodules\Payments\Application\PaymentGatewayRegistry;
use PHPUnit\Framework\TestCase;

final class PaymentOptionsTest extends TestCase
{
    public function testHostedCheckoutDoesNotPromiseExclusiveCardOrWalletSelection(): void
    {
        $gateway = (new \ReflectionClass(StripeGateway::class))->newInstanceWithoutConstructor();
        $registry = new PaymentGatewayRegistry();
        $registry->register($gateway);

        self::assertTrue($registry->supports('stripe', 'online'));
        self::assertFalse($registry->supports('stripe', 'card'));
        self::assertFalse($registry->supports('stripe', 'apple_pay'));
        self::assertFalse($registry->supports('stripe', 'google_pay'));
        self::assertFalse($registry->supports('stripe', 'cod'));
    }
}

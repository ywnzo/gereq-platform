<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Customers;

use Gereq\Customers\CustomersBootstrap;
use PHPUnit\Framework\TestCase;

final class CustomersBootstrapIdTest extends TestCase
{
    public function testIdIsCustomers(): void
    {
        $bootstrap = new CustomersBootstrap();

        self::assertSame('customers', $bootstrap->id());
    }
}

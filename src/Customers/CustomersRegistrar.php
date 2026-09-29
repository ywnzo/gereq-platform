<?php

declare(strict_types=1);

namespace Gereq\Customers;

use Gereq\Module\ModuleContext;

/**
 * Core registration for customers / contacts (formerly modules/Customers).
 */
final class CustomersRegistrar
{
    public function __construct(
        private readonly ModuleContext $context,
        private readonly CustomersBootstrap $bootstrap,
    ) {
    }

    public function register(): void
    {
        $this->bootstrap->register($this->context);
        $this->bootstrap->boot($this->context);
    }
}

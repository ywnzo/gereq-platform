<?php

declare(strict_types=1);

namespace Modules\Customers\Domain;

final class CustomerCreated
{
    public function __construct(public readonly string $customerId)
    {
    }
}

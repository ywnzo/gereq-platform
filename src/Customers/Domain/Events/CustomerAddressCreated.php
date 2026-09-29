<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain\Events;

final class CustomerAddressCreated extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $addressId, array $data, ?array $actor = null): self
    {
        return new self('customer.address.created', ['type' => 'customer_address', 'id' => $addressId], $data, $actor);
    }
}

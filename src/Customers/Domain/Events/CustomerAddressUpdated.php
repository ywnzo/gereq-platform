<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain\Events;

final class CustomerAddressUpdated extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $addressId, array $data, ?array $actor = null): self
    {
        return new self('customer.address.updated', ['type' => 'customer_address', 'id' => $addressId], $data, $actor);
    }
}

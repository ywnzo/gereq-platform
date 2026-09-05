<?php

declare(strict_types=1);

namespace Modules\Customers\Domain\Events;

final class CustomerAddressDeleted extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $addressId, array $data = [], ?array $actor = null): self
    {
        $payload = $data !== [] ? $data : ['id' => $addressId];

        return new self('customer.address.deleted', ['type' => 'customer_address', 'id' => $addressId], $payload, $actor);
    }
}

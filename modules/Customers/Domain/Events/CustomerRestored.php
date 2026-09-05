<?php

declare(strict_types=1);

namespace Modules\Customers\Domain\Events;

final class CustomerRestored extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $customerId, array $data = [], ?array $actor = null): self
    {
        $payload = $data !== [] ? $data : ['id' => $customerId, 'status' => 'active'];

        return new self('customer.restored', ['type' => 'customer', 'id' => $customerId], $payload, $actor);
    }
}

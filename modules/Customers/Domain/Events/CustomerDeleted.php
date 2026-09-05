<?php

declare(strict_types=1);

namespace Modules\Customers\Domain\Events;

final class CustomerDeleted extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $customerId, array $data = [], ?array $actor = null): self
    {
        $payload = $data !== [] ? $data : ['id' => $customerId];

        return new self('customer.deleted', ['type' => 'customer', 'id' => $customerId], $payload, $actor);
    }
}

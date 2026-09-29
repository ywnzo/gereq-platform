<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain\Events;

final class CustomerTagAttached extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $customerId, array $data, ?array $actor = null): self
    {
        return new self('customer.tag.attached', ['type' => 'customer', 'id' => $customerId], $data, $actor);
    }
}

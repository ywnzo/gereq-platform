<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain\Events;

final class CustomerContactDeleted extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $contactId, array $data = [], ?array $actor = null): self
    {
        $payload = $data !== [] ? $data : ['id' => $contactId];

        return new self('customer.contact.deleted', ['type' => 'customer_contact', 'id' => $contactId], $payload, $actor);
    }
}

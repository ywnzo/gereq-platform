<?php

declare(strict_types=1);

namespace Modules\Customers\Domain\Events;

final class CustomerContactUpdated extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $contactId, array $data, ?array $actor = null): self
    {
        return new self('customer.contact.updated', ['type' => 'customer_contact', 'id' => $contactId], $data, $actor);
    }
}

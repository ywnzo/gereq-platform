<?php

declare(strict_types=1);

namespace Modules\Customers\Domain\Events;

final class CustomerRelationCreated extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $relationId, array $data, ?array $actor = null): self
    {
        return new self('customer.relation.created', ['type' => 'customer_relation', 'id' => $relationId], $data, $actor);
    }
}

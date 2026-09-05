<?php

declare(strict_types=1);

namespace Modules\Customers\Domain\Events;

final class CustomerRelationDeleted extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $relationId, array $data = [], ?array $actor = null): self
    {
        $payload = $data !== [] ? $data : ['id' => $relationId];

        return new self('customer.relation.deleted', ['type' => 'customer_relation', 'id' => $relationId], $payload, $actor);
    }
}

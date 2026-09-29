<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain;

use Gereq\Event\EventEnvelope;
use Gereq\Event\PlatformEvent;
use Ramsey\Uuid\Uuid;

/** BC: CRM listener still listens to this class. */
final class CustomerCreated implements PlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public function __construct(
        public readonly string $customerId,
        private readonly array $data = [],
        private readonly ?array $actor = null,
    ) {
    }

    public function envelope(): EventEnvelope
    {
        $payload = $this->data !== [] ? $this->data : ['id' => $this->customerId];

        return new EventEnvelope(
            id: 'evt_' . Uuid::uuid7()->toString(),
            type: 'customer.created',
            version: '1',
            occurredAt: new \DateTimeImmutable(),
            actor: $this->actor ?? ['type' => 'system', 'id' => null],
            resource: ['type' => 'customer', 'id' => $this->customerId],
            data: $payload,
        );
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain\Events;

use Gereq\Event\EventEnvelope;
use Gereq\Event\PlatformEvent;
use Ramsey\Uuid\Uuid;

abstract class AbstractCustomerPlatformEvent implements PlatformEvent
{
    /**
     * @param array{type: string, id: string} $resource
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public function __construct(
        private readonly string $type,
        private readonly array $resource,
        private readonly array $data,
        private readonly ?array $actor = null,
        private readonly string $version = '1',
    ) {
    }

    public function envelope(): EventEnvelope
    {
        return new EventEnvelope(
            id: 'evt_' . Uuid::uuid7()->toString(),
            type: $this->type,
            version: $this->version,
            occurredAt: new \DateTimeImmutable(),
            actor: $this->actor ?? ['type' => 'system', 'id' => null],
            resource: $this->resource,
            data: $this->data,
        );
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }
}

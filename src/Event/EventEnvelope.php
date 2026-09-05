<?php

declare(strict_types=1);

namespace Gereq\Event;

final class EventEnvelope
{
    /**
     * @param array{type: string, id: ?string} $actor
     * @param array{type: string, id: string} $resource
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $version,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly array $actor,
        public readonly array $resource,
        public readonly array $data,
        public readonly ?string $correlationId = null,
        public readonly ?string $causationId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'version' => $this->version,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'actor' => $this->actor,
            'resource' => $this->resource,
            'data' => $this->data,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
        ];
    }

    /** @return array<string, mixed> */
    public function webhookPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'version' => $this->version,
            'created_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'data' => $this->data,
        ];
    }
}

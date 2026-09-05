<?php
declare(strict_types=1);
namespace Modules\Documents\Domain\Events;
use Gereq\Event\EventEnvelope;
use Gereq\Event\PlatformEvent;
use Ramsey\Uuid\Uuid;

abstract class AbstractDocumentEvent implements PlatformEvent
{
    public function __construct(
        private readonly string $type,
        private readonly array $resource,
        private readonly array $data,
        private readonly ?array $actor = null,
    ) {}
    public function envelope(): EventEnvelope
    {
        return new EventEnvelope(
            id: 'evt_' . Uuid::uuid7()->toString(),
            type: $this->type,
            version: '1',
            occurredAt: new \DateTimeImmutable(),
            actor: $this->actor ?? ['type' => 'system', 'id' => null],
            resource: $this->resource,
            data: $this->data,
        );
    }
    public function type(): string { return $this->type; }
}
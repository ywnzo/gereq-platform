<?php

declare(strict_types=1);

namespace Gereq\Event;

use Gereq\Hook\HookManager;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\RequestStack;

final class EventBus
{
    /** @var array<string, list<array{id: string, listener: callable, priority: int}>> */
    private array $listeners = [];

    private int $sequence = 0;

    private mixed $tracer = null;

    public function __construct(
        private readonly ?HookManager $hooks = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /** @param callable(string, float): void|null $tracer */
    public function setTracer(?callable $tracer): void
    {
        $this->tracer = $tracer;
    }

    public function listen(string $eventClass, callable $listener, int $priority = 0): string
    {
        $id = 'event_' . (++$this->sequence);
        $this->listeners[$eventClass][] = [
            'id' => $id,
            'listener' => $listener,
            'priority' => $priority,
        ];

        return $id;
    }

    public function subscribe(string $eventClass, callable $listener, int $priority = 0): string
    {
        return $this->listen($eventClass, $listener, $priority);
    }

    public function dispatch(object $event): object
    {
        $start = hrtime(true);
        $listeners = $this->listeners[$event::class] ?? [];
        usort($listeners, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($listeners as $item) {
            ($item['listener'])($event);
        }

        if ($event instanceof PlatformEvent) {
            $envelope = $event->envelope();
            $this->hooks?->doAction('platform.event.dispatched', $envelope, $event);
        }

        if (is_callable($this->tracer)) {
            ($this->tracer)($event::class, (hrtime(true) - $start) / 1_000_000);
        }

        return $event;
    }

    /**
     * @param array{type: string, id: ?string}|null $actor
     * @param array{type: string, id: string} $resource
     * @param array<string, mixed> $data
     */
    public function emit(
        string $type,
        array $resource,
        array $data,
        string $version = '1',
        ?array $actor = null,
    ): EventEnvelope {
        $request = $this->requestStack?->getCurrentRequest();
        $correlation = is_string($request?->attributes->get('correlation_id'))
            ? (string) $request->attributes->get('correlation_id')
            : null;
        if ($actor === null) {
            $attr = $request?->attributes->get('_api_actor');
            $actor = is_array($attr) ? $attr : ['type' => 'system', 'id' => null];
        }

        $envelope = new EventEnvelope(
            id: 'evt_' . Uuid::uuid7()->toString(),
            type: $type,
            version: $version,
            occurredAt: new \DateTimeImmutable(),
            actor: $actor,
            resource: $resource,
            data: $data,
            correlationId: $correlation,
        );

        $this->hooks?->doAction('platform.event.dispatched', $envelope, null);

        return $envelope;
    }

    public function remove(string $id): bool
    {
        $removed = false;

        foreach ($this->listeners as $eventClass => $items) {
            $this->listeners[$eventClass] = array_values(array_filter(
                $items,
                static function (array $item) use ($id, &$removed): bool {
                    if ($item['id'] === $id) {
                        $removed = true;

                        return false;
                    }

                    return true;
                }
            ));
        }

        return $removed;
    }
}

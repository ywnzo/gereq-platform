<?php

declare(strict_types=1);

namespace Gereq\Event;

final class EventBus
{
    /** @var array<string, list<array{id: string, listener: callable, priority: int}>> */
    private array $listeners = [];

    private int $sequence = 0;

    private mixed $tracer = null;

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

        if (is_callable($this->tracer)) {
            ($this->tracer)($event::class, (hrtime(true) - $start) / 1_000_000);
        }

        return $event;
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

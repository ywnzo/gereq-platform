<?php

declare(strict_types=1);

namespace Gereq\Event;

final class EventRegistry
{
    /** @var array<string, EventDefinition> */
    private array $events = [];

    public function register(EventDefinition $definition): void
    {
        $this->events[$definition->type] = $definition;
    }

    public function get(string $type): ?EventDefinition
    {
        return $this->events[$type] ?? null;
    }

    /** @return list<EventDefinition> */
    public function all(): array
    {
        $items = array_values($this->events);
        usort($items, static fn (EventDefinition $a, EventDefinition $b): int => $a->type <=> $b->type);

        return $items;
    }

    /** @return list<EventDefinition> */
    public function webhookEnabled(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (EventDefinition $e): bool => $e->webhookEnabled
        ));
    }

    /** @return array<string, list<EventDefinition>> */
    public function groupedByModule(): array
    {
        $groups = [];
        foreach ($this->all() as $event) {
            $groups[$event->module][] = $event;
        }
        ksort($groups);

        return $groups;
    }
}

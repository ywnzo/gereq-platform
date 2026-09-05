<?php

declare(strict_types=1);

namespace Gereq\Hook;

final class HookManager
{
    /** @var array<string, list<array{id: string, callback: callable, priority: int}>> */
    private array $actions = [];

    /** @var array<string, list<array{id: string, callback: callable, priority: int}>> */
    private array $filters = [];

    private int $sequence = 0;

    public function action(string $hook, callable $callback, int $priority = HookPriority::EARLY): string
    {
        return $this->add($this->actions, $hook, $callback, $priority);
    }

    public function addAction(string $hook, callable $callback, int $priority = HookPriority::EARLY): string
    {
        return $this->action($hook, $callback, $priority);
    }

    public function addFilter(string $hook, callable $callback, int $priority = HookPriority::EARLY): string
    {
        return $this->add($this->filters, $hook, $callback, $priority);
    }

    public function doAction(string $hook, mixed ...$payload): void
    {
        foreach ($this->listeners($this->actions, $hook) as $listener) {
            ($listener['callback'])(...$payload);
        }
    }

    public function filter(string $hook, mixed $value, mixed ...$context): mixed
    {
        foreach ($this->listeners($this->filters, $hook) as $listener) {
            $value = ($listener['callback'])($value, ...$context);
        }

        return $value;
    }

    public function remove(string $id): bool
    {
        $removed = $this->removeFrom($this->actions, $id);
        $removed = $this->removeFrom($this->filters, $id) || $removed;

        return $removed;
    }

    public function hasAction(string $hook): bool
    {
        return isset($this->actions[$hook]) && $this->actions[$hook] !== [];
    }

    public function hasFilter(string $hook): bool
    {
        return isset($this->filters[$hook]) && $this->filters[$hook] !== [];
    }

    /**
     * @return list<array{name: string, type: 'action'|'filter', listeners: int}>
     */
    public function inventory(): array
    {
        $items = [];

        foreach ($this->actions as $name => $listeners) {
            $items[] = [
                'name' => $name,
                'type' => 'action',
                'listeners' => count($listeners),
            ];
        }

        foreach ($this->filters as $name => $listeners) {
            $items[] = [
                'name' => $name,
                'type' => 'filter',
                'listeners' => count($listeners),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $items;
    }

    /**
     * @param array<string, list<array{id: string, callback: callable, priority: int}>> $bag
     */
    private function add(array &$bag, string $hook, callable $callback, int $priority): string
    {
        $id = 'hook_' . (++$this->sequence);
        $bag[$hook][] = [
            'id' => $id,
            'callback' => $callback,
            'priority' => $priority,
        ];

        return $id;
    }

    /**
     * @param array<string, list<array{id: string, callback: callable, priority: int}>> $bag
     * @return list<array{id: string, callback: callable, priority: int}>
     */
    private function listeners(array $bag, string $hook): array
    {
        $listeners = $bag[$hook] ?? [];
        usort($listeners, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $listeners;
    }

    /**
     * @param array<string, list<array{id: string, callback: callable, priority: int}>> $bag
     */
    private function removeFrom(array &$bag, string $id): bool
    {
        $removed = false;

        foreach ($bag as $hook => $listeners) {
            $bag[$hook] = array_values(array_filter(
                $listeners,
                static function (array $listener) use ($id, &$removed): bool {
                    if ($listener['id'] === $id) {
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

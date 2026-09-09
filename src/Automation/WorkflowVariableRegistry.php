<?php

declare(strict_types=1);

namespace Gereq\Automation;

/**
 * Shared catalog of workflow template variables.
 * Register from any module via ModuleContext::workflowVariables(), or resolve the registry directly.
 */
final class WorkflowVariableRegistry
{
    /** @var array<string, WorkflowVariableDefinition> */
    private array $variables = [];

    public function register(WorkflowVariableDefinition $definition): void
    {
        $this->variables[$definition->path] = $definition;
    }

    public function registerMany(WorkflowVariableDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function get(string $path): ?WorkflowVariableDefinition
    {
        return $this->variables[$path] ?? null;
    }

    public function has(string $path): bool
    {
        return isset($this->variables[$path]);
    }

    /** @return list<WorkflowVariableDefinition> */
    public function all(): array
    {
        $items = array_values($this->variables);
        usort($items, static function (WorkflowVariableDefinition $a, WorkflowVariableDefinition $b): int {
            $group = $a->group <=> $b->group;
            if ($group !== 0) {
                return $group;
            }

            return $a->path <=> $b->path;
        });

        return $items;
    }

    /** @return array<string, list<WorkflowVariableDefinition>> */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->all() as $variable) {
            $groups[$variable->group][] = $variable;
        }

        return $groups;
    }

    /** @return list<array{path: string, module: string, label: string, description: string, group: string, example: string, token: string}> */
    public function toArray(): array
    {
        return array_map(
            static fn (WorkflowVariableDefinition $v): array => $v->toArray(),
            $this->all(),
        );
    }
}

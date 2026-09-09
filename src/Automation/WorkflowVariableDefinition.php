<?php

declare(strict_types=1);

namespace Gereq\Automation;

/**
 * Catalog entry for a workflow template variable ({{path}}).
 * Modules register these for the editor UI; runtime resolution stays in the runner context.
 */
final class WorkflowVariableDefinition
{
    public function __construct(
        public readonly string $path,
        public readonly string $module,
        public readonly string $label = '',
        public readonly string $description = '',
        public readonly string $group = 'general',
        public readonly string $example = '',
    ) {
    }

    public function withLabel(string $label): self
    {
        return new self($this->path, $this->module, $label, $this->description, $this->group, $this->example);
    }

    public function withDescription(string $description): self
    {
        return new self($this->path, $this->module, $this->label, $description, $this->group, $this->example);
    }

    public function withGroup(string $group): self
    {
        return new self($this->path, $this->module, $this->label, $this->description, $group, $this->example);
    }

    public function withExample(string $example): self
    {
        return new self($this->path, $this->module, $this->label, $this->description, $this->group, $example);
    }

    /** @return array{path: string, module: string, label: string, description: string, group: string, example: string, token: string} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'module' => $this->module,
            'label' => $this->label !== '' ? $this->label : $this->path,
            'description' => $this->description,
            'group' => $this->group,
            'example' => $this->example,
            'token' => '{{' . $this->path . '}}',
        ];
    }
}

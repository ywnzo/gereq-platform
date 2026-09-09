<?php

declare(strict_types=1);

namespace Gereq\Api;

final class ApiRouteDefinition
{
    /**
     * @param list<string> $methods
     * @param array{0: class-string, 1: string}|class-string|callable $handler
     * @param list<string> $scopes
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $module,
        public readonly string $path,
        public readonly array $methods,
        public readonly mixed $handler,
        public array $scopes = [],
        public ?string $permission = null,
        public string $summary = '',
        public array $meta = [],
    ) {
    }

    public function scope(string ...$scopes): self
    {
        $this->scopes = array_values(array_unique([...$this->scopes, ...$scopes]));

        return $this;
    }

    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    /** OpenAPI request body schema from ValidationRegistry ruleset name. */
    public function ruleset(string $ruleset): self
    {
        $this->meta['ruleset'] = $ruleset;

        return $this;
    }

    public function fullPath(string $apiPrefix): string
    {
        return rtrim($apiPrefix, '/') . '/' . trim($this->module, '/') . '/' . ltrim($this->path, '/');
    }
}

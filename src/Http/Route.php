<?php

declare(strict_types=1);

namespace Gereq\Http;

final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    private ?string $name = null;

    private ?string $permission = null;

    private ?string $module = null;

    /** @var list<string> */
    private array $scopes = [];

    /** @var array<string, string> */
    private array $requirements = [];

    /**
     * @param list<string> $methods
     * @param array{0: class-string, 1: string}|class-string|callable $handler
     */
    public function __construct(
        private string $path,
        private array $methods,
        private mixed $handler,
    ) {
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function middleware(string ...$middleware): self
    {
        foreach ($middleware as $item) {
            $this->middleware[] = $item;
        }

        return $this;
    }

    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    public function module(string $moduleId): self
    {
        $this->module = $moduleId;

        return $this;
    }

    public function scope(string ...$scopes): self
    {
        foreach ($scopes as $scope) {
            $this->scopes[] = $scope;
        }
        $this->scopes = array_values(array_unique($this->scopes));

        return $this;
    }

    public function where(string $param, string $regex): self
    {
        $this->requirements[$param] = $regex;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /** @return list<string> */
    public function getMethods(): array
    {
        return $this->methods;
    }

    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /** @return list<string> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getPermission(): ?string
    {
        return $this->permission;
    }

    public function getModule(): ?string
    {
        return $this->module;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** @return array<string, string> */
    public function getRequirements(): array
    {
        return $this->requirements;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    public function prependMiddleware(string ...$middleware): void
    {
        $this->middleware = array_values(array_unique([...$middleware, ...$this->middleware]));
    }
}

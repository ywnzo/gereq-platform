<?php

declare(strict_types=1);

namespace Gereq\Http;

final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    private ?string $name = null;

    private ?string $permission = null;

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

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    public function prependMiddleware(string ...$middleware): void
    {
        $this->middleware = array_values(array_unique([...$middleware, ...$this->middleware]));
    }
}

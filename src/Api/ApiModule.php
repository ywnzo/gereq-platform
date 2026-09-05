<?php

declare(strict_types=1);

namespace Gereq\Api;

final class ApiModule
{
    /** @var list<ApiRouteDefinition> */
    private array $routes = [];

    public function __construct(private readonly string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function get(string $path, mixed $handler): ApiRouteDefinition
    {
        return $this->add($path, ['GET'], $handler);
    }

    public function post(string $path, mixed $handler): ApiRouteDefinition
    {
        return $this->add($path, ['POST'], $handler);
    }

    public function put(string $path, mixed $handler): ApiRouteDefinition
    {
        return $this->add($path, ['PUT'], $handler);
    }

    public function patch(string $path, mixed $handler): ApiRouteDefinition
    {
        return $this->add($path, ['PATCH'], $handler);
    }

    public function delete(string $path, mixed $handler): ApiRouteDefinition
    {
        return $this->add($path, ['DELETE'], $handler);
    }

    /**
     * CRUD resource helper: index/store/show/update/destroy.
     *
     * @param class-string $controller
     */
    public function resource(string $name, string $controller, string $scopePrefix): void
    {
        $base = '/' . trim($name, '/');
        $this->get($base === '/' ? '/' : $base, [$controller, 'index'])->scope($scopePrefix . '.read');
        $this->post($base === '/' ? '/' : $base, [$controller, 'store'])->scope($scopePrefix . '.write');
        $this->get(($base === '/' ? '' : $base) . '/{id}', [$controller, 'show'])->scope($scopePrefix . '.read');
        $this->patch(($base === '/' ? '' : $base) . '/{id}', [$controller, 'update'])->scope($scopePrefix . '.write');
        $this->delete(($base === '/' ? '' : $base) . '/{id}', [$controller, 'destroy'])->scope($scopePrefix . '.write');
    }

    /** @return list<ApiRouteDefinition> */
    public function routes(): array
    {
        return $this->routes;
    }

    private function add(string $path, array $methods, mixed $handler): ApiRouteDefinition
    {
        $route = new ApiRouteDefinition($this->name, $path === '' ? '/' : $path, $methods, $handler);
        $this->routes[] = $route;

        return $route;
    }
}

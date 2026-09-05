<?php

declare(strict_types=1);

namespace Gereq\Api;

use Gereq\Config\Repository;
use Gereq\Http\Router;

final class ApiRegistry
{
    /** @var array<string, ApiModule> */
    private array $modules = [];

    /** @var list<string> */
    private array $scopes = [];

    public function __construct(private readonly Repository $config)
    {
    }

    public function module(string $name, callable $callback): void
    {
        $module = $this->modules[$name] ?? new ApiModule($name);
        $callback($module);
        $this->modules[$name] = $module;

        foreach ($module->routes() as $route) {
            foreach ($route->scopes as $scope) {
                $this->scopes[$scope] = $scope;
            }
        }
    }

    /** @return array<string, ApiModule> */
    public function modules(): array
    {
        return $this->modules;
    }

    /** @return list<ApiRouteDefinition> */
    public function allRoutes(): array
    {
        $routes = [];
        foreach ($this->modules as $module) {
            foreach ($module->routes() as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        $scopes = array_values($this->scopes);
        sort($scopes);

        return $scopes;
    }

    public function registerKnownScope(string $scope): void
    {
        $this->scopes[$scope] = $scope;
    }

    public function bind(Router $router): void
    {
        $prefix = (string) $this->config->get('api.prefix', '/api/v1');

        foreach ($this->allRoutes() as $index => $definition) {
            $path = $definition->fullPath($prefix);
            $path = preg_replace('#/+#', '/', $path) ?: $path;
            $path = rtrim($path, '/') ?: $path;

            $route = $router->add($path, $definition->methods, $definition->handler);
            $route->name('api.' . $definition->module . '.' . $index);
            $route->middleware('api');
            if ($definition->scopes !== []) {
                $route->scope(...$definition->scopes);
            }
            if ($definition->permission !== null) {
                $route->permission($definition->permission);
            }
        }
    }
}

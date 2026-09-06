<?php

declare(strict_types=1);

namespace Gereq\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouteCollection;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    private string $groupPrefix = '';

    /** @var list<string> */
    private array $groupMiddleware = [];

    private ?string $groupPermission = null;

    private ?string $groupModule = null;

    private int $anonymous = 0;

    public function get(string $path, mixed $handler): Route
    {
        return $this->add($path, ['GET'], $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->add($path, ['POST'], $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->add($path, ['PUT'], $handler);
    }

    public function patch(string $path, mixed $handler): Route
    {
        return $this->add($path, ['PATCH'], $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->add($path, ['DELETE'], $handler);
    }

    /**
     * @param array{middleware?: list<string>, permission?: string, module?: string} $options
     */
    public function group(string $prefix, callable $callback, array $options = []): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;
        $previousPermission = $this->groupPermission;
        $previousModule = $this->groupModule;

        $this->groupPrefix = rtrim($previousPrefix . '/' . trim($prefix, '/'), '/');
        if (isset($options['middleware'])) {
            $this->groupMiddleware = array_merge($this->groupMiddleware, $options['middleware']);
        }
        if (isset($options['permission'])) {
            $this->groupPermission = $options['permission'];
        }
        if (isset($options['module'])) {
            $this->groupModule = $options['module'];
        }

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
        $this->groupPermission = $previousPermission;
        $this->groupModule = $previousModule;
    }

    public function add(string $path, array $methods, mixed $handler): Route
    {
        $fullPath = $this->join($this->groupPrefix, $path);
        $route = new Route($fullPath, $methods, $handler);

        if ($this->groupMiddleware !== []) {
            $route->prependMiddleware(...$this->groupMiddleware);
        }

        if ($this->groupPermission !== null) {
            $route->permission($this->groupPermission);
        }

        if ($this->groupModule !== null) {
            $route->module($this->groupModule);
        }

        $this->routes[] = $route;

        return $route;
    }

    /**
     * @return array{route: Route, parameters: array<string, string>}
     */
    public function match(Request $request): array
    {
        $collection = $this->collection();
        $context = new RequestContext();
        $context->fromRequest($request);
        $matcher = new UrlMatcher($collection, $context);
        $parameters = $matcher->match($request->getPathInfo());
        $name = $parameters['_route'];
        unset($parameters['_route'], $parameters['_controller']);

        return [
            'route' => $this->findByName($name),
            'parameters' => $parameters,
        ];
    }

    public function url(string $name, array $parameters = [], ?Request $request = null): string
    {
        $collection = $this->collection();
        $context = new RequestContext();
        if ($request !== null) {
            $context->fromRequest($request);
        }

        $generator = new UrlGenerator($collection, $context);

        return $generator->generate($name, $parameters);
    }

    /** Absolute URL for OAuth / webhooks (Google requires full https/http URI). */
    public function absoluteUrl(string $name, array $parameters = [], ?Request $request = null): string
    {
        $collection = $this->collection();
        $context = new RequestContext();
        if ($request !== null) {
            $context->fromRequest($request);
        }

        $generator = new UrlGenerator($collection, $context);

        return $generator->generate($name, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function has(string $name): bool
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $name) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Route> */
    public function all(): array
    {
        return $this->routes;
    }

    private function collection(): RouteCollection
    {
        $collection = new RouteCollection();

        foreach ($this->routes as $index => $route) {
            $name = $route->getName() ?? 'anonymous_' . $index;
            if ($route->getName() === null) {
                $route->name($name);
            }

            $collection->add($name, new SymfonyRoute(
                $route->getPath(),
                ['_controller' => $route->getHandler()],
                [],
                [],
                '',
                [],
                $route->getMethods(),
            ));
        }

        return $collection;
    }

    private function findByName(string $name): Route
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        throw new \RuntimeException(sprintf('Route "%s" nebyla nalezena.', $name));
    }

    private function join(string $prefix, string $path): string
    {
        $path = '/' . trim($prefix . '/' . ltrim($path, '/'), '/');

        return $path;
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Http;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ControllerDispatcher
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function dispatch(Route $route, Request $request, array $parameters): Response
    {
        $handler = $route->getHandler();

        if (is_callable($handler) && !is_array($handler)) {
            $result = $handler($request, $parameters);

            return $this->normalize($result);
        }

        if (is_string($handler)) {
            $handler = [$handler, '__invoke'];
        }

        if (!is_array($handler) || count($handler) !== 2) {
            throw new \RuntimeException('Neplatný route handler.');
        }

        [$class, $method] = $handler;
        $controller = $this->container->get($class);
        $args = $this->arguments($controller, $method, $request, $parameters);
        $result = $controller->{$method}(...$args);

        return $this->normalize($result);
    }

    /**
     * @param array<string, string> $parameters
     * @return list<mixed>
     */
    private function arguments(object $controller, string $method, Request $request, array $parameters): array
    {
        $reflection = new \ReflectionMethod($controller, $method);
        $args = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $class = $type->getName();
                if (is_a($class, Request::class, true)) {
                    $args[] = $request;
                    continue;
                }
                if ($this->container->has($class)) {
                    $args[] = $this->container->get($class);
                    continue;
                }
            }

            if (array_key_exists($name, $parameters)) {
                $args[] = $parameters[$name];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            throw new \RuntimeException('Nelze vyřešit argument $' . $name . ' pro ' . $controller::class . '::' . $method);
        }

        return $args;
    }

    private function normalize(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return new Response($result);
        }

        throw new \RuntimeException('Controller musí vrátit Response.');
    }
}

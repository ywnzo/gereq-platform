<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Pipeline
{
    /**
     * @param list<class-string<MiddlewareInterface>|MiddlewareInterface> $middleware
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private array $middleware,
    ) {
    }

    public function handle(Request $request, callable $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            function (callable $next, mixed $middleware): callable {
                return function (Request $request) use ($next, $middleware): Response {
                    $instance = $middleware instanceof MiddlewareInterface
                        ? $middleware
                        : $this->container->get($middleware);

                    return $instance->process($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }
}

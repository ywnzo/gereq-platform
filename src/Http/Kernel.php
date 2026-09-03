<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Auth\Authenticator;
use Gereq\Debug\DebugBar;
use Gereq\Http\Middleware\AuthMiddleware;
use Gereq\Http\Middleware\CsrfMiddleware;
use Gereq\Http\Middleware\GuestMiddleware;
use Gereq\Http\Middleware\PermissionMiddleware;
use Gereq\Http\Middleware\Pipeline;
use Gereq\Http\Middleware\SecurityHeadersMiddleware;
use Gereq\Http\Middleware\StartSessionMiddleware;
use Gereq\Validation\ValidationException;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final class Kernel
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Router $router,
        private readonly ControllerDispatcher $dispatcher,
        private readonly RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly ExceptionHandler $errors,
        private readonly FormState $formState,
        private readonly DebugBar $debugBar,
        private readonly string $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $this->requestStack->push($request);
        $this->debugBar->mark('request');

        try {
            $response = $this->dispatch($request);
        } catch (ValidationException $exception) {
            $response = $this->errors->decorate($request, $this->formState->respond($request, $exception));
        } catch (\Throwable $exception) {
            $this->errors->report($exception);
            $response = $this->errors->render($request, $exception);
        }

        $this->auth->applyCookies($response);
        $response->headers->set('Connection', 'close');
        $response->setProtocolVersion('1.1');
        $this->debugBar->mark('done');

        return $this->debugBar->inject($request, $response);
    }

    private function dispatch(Request $request): Response
    {
        $aliases = [
            'auth' => AuthMiddleware::class,
            'guest' => GuestMiddleware::class,
            'csrf' => CsrfMiddleware::class,
        ];

        $matched = $this->router->match($request);
        $route = $matched['route'];
        $parameters = $matched['parameters'];
        $request->attributes->set('_route', $route->getName());
        $request->attributes->set('_route_params', $parameters);

        $middleware = [
            new StartSessionMiddleware($this->basePath),
            SecurityHeadersMiddleware::class,
            CsrfMiddleware::class,
        ];

        foreach ($route->getMiddleware() as $item) {
            $middleware[] = $aliases[$item] ?? $item;
        }

        if ($route->getPermission() !== null) {
            $middleware[] = $this->container->get(PermissionMiddleware::class)->withPermission($route->getPermission());
        }

        $pipeline = new Pipeline($this->container, $middleware);

        return $pipeline->handle($request, function (Request $request) use ($route, $parameters): Response {
            return $this->dispatcher->dispatch($route, $request, $parameters);
        });
    }
}

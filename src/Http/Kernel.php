<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Auth\Authenticator;
use Gereq\Debug\DebugBar;
use Gereq\Http\Middleware\AuthMiddleware;
use Gereq\Http\Middleware\CsrfMiddleware;
use Gereq\Http\Middleware\GuestMiddleware;
use Gereq\Http\Middleware\ModuleCurrentMiddleware;
use Gereq\Http\Middleware\PermissionMiddleware;
use Gereq\Http\Middleware\Pipeline;
use Gereq\Http\Middleware\SecurityHeadersMiddleware;
use Gereq\Http\Middleware\SetLocaleMiddleware;
use Gereq\Http\Middleware\StartSessionMiddleware;
use Gereq\Module\ModuleManager;
use Gereq\Validation\ValidationException;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    ) {
    }

    public function handle(Request $request): Response
    {
        TrustedProxies::configure($request);
        $this->requestStack->push($request);
        $this->debugBar->mark('request');

        $static = ProductAdmin::staticFile($request) ?? Storefront::staticFile($request);
        if ($static !== null) {
            $this->debugBar->mark('done');

            return $static;
        }

        $path = $request->getPathInfo();
        if (strtoupper($request->getMethod()) === 'OPTIONS' && str_starts_with($path, '/api/v1/store/')) {
            $response = PublicCors::apply(new Response('', 204), $request, 'GET, POST, PATCH, DELETE, OPTIONS');
            $this->debugBar->mark('done');

            return $this->debugBar->inject($request, $response);
        }

        try {
            $response = $this->dispatch($request);
        } catch (ValidationException $exception) {
            $response = $this->errors->decorate($request, $this->formState->respond($request, $exception));
        } catch (\Throwable $exception) {
            $this->errors->report($exception);
            $response = $this->errors->render($request, $exception);
        }

        if (str_starts_with($path, '/api/v1/store/')) {
            PublicCors::apply($response, $request, 'GET, POST, PATCH, DELETE, OPTIONS');
        }

        $this->auth->applyCookies($response);
        // Keep binary/stream responses eligible for Content-Length + Range (HTML5 video/audio).
        if (!$response instanceof BinaryFileResponse && !$response instanceof StreamedResponse) {
            $response->headers->set('Connection', 'close');
        }
        $response->setProtocolVersion('1.1');
        $this->debugBar->mark('done');

        return $this->debugBar->inject($request, $response);
    }

    private function dispatch(Request $request): Response
    {
        $matched = $this->router->match($request);
        $route = $matched['route'];
        $parameters = $matched['parameters'];
        $request->attributes->set('_route', $route->getName());
        $request->attributes->set('_route_params', $parameters);
        $request->attributes->set('_gereq_route', $route);

        $aliases = [
            'auth' => AuthMiddleware::class,
            'guest' => GuestMiddleware::class,
            'csrf' => CsrfMiddleware::class,
            'api' => \Gereq\Api\Http\ApiAuthMiddleware::class,
            'api.rate' => \Gereq\Api\Http\ApiRateLimitMiddleware::class,
            'api.correlation' => \Gereq\Api\Http\CorrelationIdMiddleware::class,
        ];

        $middleware = [
            StartSessionMiddleware::class,
            SetLocaleMiddleware::class,
            SecurityHeadersMiddleware::class,
            CsrfMiddleware::class,
            \Gereq\Api\Http\CorrelationIdMiddleware::class,
            \Gereq\Http\Middleware\SetupMiddleware::class,
            \Gereq\Http\Middleware\ContentWorkspaceMiddleware::class,
        ];

        $isApi = str_starts_with((string) $route->getPath(), '/api/');
        if ($isApi) {
            $middleware[] = \Gereq\Api\Http\ApiRateLimitMiddleware::class;
            if (class_exists(\Modules\Vitality\Http\Middleware\VitalityTimingMiddleware::class)) {
                $middleware[] = \Modules\Vitality\Http\Middleware\VitalityTimingMiddleware::class;
            }
        }

        if (PublicCors::isPublicApiPath((string) $route->getPath())) {
            $middleware[] = \Gereq\Api\Http\PublicApiAuthMiddleware::class;
        }

        foreach ($route->getMiddleware() as $item) {
            $middleware[] = $aliases[$item] ?? $item;
        }

        if (!$isApi && $route->getPermission() !== null) {
            $middleware[] = $this->container->get(PermissionMiddleware::class)->withPermission($route->getPermission());
        }

        $moduleId = $route->getModule() ?? $this->inferModuleId($route->getName());
        if ($moduleId !== null && !$isApi) {
            $middleware[] = $this->container->get(ModuleCurrentMiddleware::class)->forModule($moduleId);
        }

        $pipeline = new Pipeline($this->container, $middleware);

        return $pipeline->handle($request, function (Request $request) use ($route, $parameters): Response {
            return $this->dispatcher->dispatch($route, $request, $parameters);
        });
    }

    private function inferModuleId(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        // admin.calendar.index → calendar; admin.modules.* is excluded (not under modules/)
        if (!preg_match('/^admin\.([a-z0-9_]+)(?:\.|$)/', $routeName, $m)) {
            return null;
        }

        $id = $m[1];
        if ($id === 'modules' || $id === 'setup' || $id === 'auth' || $id === 'dashboard' || $id === 'account') {
            return null;
        }

        /** @var ModuleManager $modules */
        $modules = $this->container->get(ModuleManager::class);

        return $modules->manifest($id) !== null ? $id : null;
    }
}

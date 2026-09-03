<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly Router $router,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->auth->check()) {
            return new RedirectResponse($this->router->url('admin.dashboard', [], $request));
        }

        return $next($request);
    }
}

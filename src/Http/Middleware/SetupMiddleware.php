<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Gereq\Setup\SetupState;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Redirects authenticated admins to the first-run wizard until setup is complete. */
final class SetupMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly SetupState $setup,
        private readonly Router $router,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->auth->check() || $this->setup->isComplete()) {
            return $next($request);
        }

        $name = (string) $request->attributes->get('_route', '');
        if ($name === '' || str_starts_with($name, 'admin.setup') || $name === 'admin.logout' || str_starts_with($name, 'admin.ares.')) {
            return $next($request);
        }

        if (!str_starts_with($name, 'admin.')) {
            return $next($request);
        }

        return new RedirectResponse($this->router->url('admin.setup', [], $request));
    }
}

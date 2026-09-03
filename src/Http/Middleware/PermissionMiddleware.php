<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Auth\Authenticator;
use Gereq\Http\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly ?string $permission = null,
    ) {
    }

    public function withPermission(string $permission): self
    {
        return new self($this->auth, $permission);
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->permission === null) {
            return $next($request);
        }

        $user = $this->auth->user();
        if ($user === null || !$user->can($this->permission)) {
            throw new AccessDeniedHttpException();
        }

        return $next($request);
    }
}

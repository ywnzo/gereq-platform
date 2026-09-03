<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Http\Exception\CsrfTokenMismatchException;
use Gereq\Security\Csrf;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->getMethod(), self::SAFE, true)) {
            return $next($request);
        }

        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return $next($request);
        }

        $token = (string) (
            $request->request->get('_csrf')
            ?? $request->headers->get('X-CSRF-TOKEN')
            ?? ''
        );

        if (!$this->csrf->isValid($token)) {
            throw new CsrfTokenMismatchException();
        }

        return $next($request);
    }
}

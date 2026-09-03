<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Security\ContentSecurityPolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContentSecurityPolicy $csp)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $nonce = $this->csp->nonce();
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', $this->csp->header($nonce));

        return $response;
    }
}

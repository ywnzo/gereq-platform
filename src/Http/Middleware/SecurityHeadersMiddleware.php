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

        $allowFrame = $response->headers->get(ContentSecurityPolicy::HEADER_ALLOW_FRAME) === '1';
        $response->headers->remove(ContentSecurityPolicy::HEADER_ALLOW_FRAME);

        $customCsp = $response->headers->get(ContentSecurityPolicy::HEADER_OVERRIDE);
        $response->headers->remove(ContentSecurityPolicy::HEADER_OVERRIDE);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', $allowFrame ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(
            'Content-Security-Policy',
            is_string($customCsp) && $customCsp !== '' ? $customCsp : $this->csp->header($nonce),
        );

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

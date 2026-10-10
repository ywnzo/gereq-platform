<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Api\ApiResponse;
use Gereq\Customers\Application\StoreCookieBridge;
use Gereq\Http\Exception\CsrfTokenMismatchException;
use Gereq\Http\PublicCors;
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

        $path = $request->getPathInfo();

        // Store cookie sessions: browsers auto-send gereq_store_token. Require
        // allowlisted Origin/Referer unless the client sent an explicit Bearer
        // (API clients / FE Authorization header) — CSRF cannot forge that.
        if (str_starts_with($path, '/api/v1/store/')) {
            $blocked = $this->storeCookieCsrfBlock($request);
            if ($blocked !== null) {
                return $blocked;
            }

            return $next($request);
        }

        if (str_starts_with($path, '/api/')) {
            return $next($request);
        }

        // GEREQ embed widgets (Ticketing / Security) — cross-origin JSON, no session CSRF.
        if (
            str_starts_with($path, '/tickets/api/')
            || str_starts_with($path, '/security/api/')
        ) {
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

    private function storeCookieCsrfBlock(Request $request): ?Response
    {
        $auth = (string) $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+\S+/i', $auth) === 1) {
            return null;
        }

        $cookie = $request->cookies->get(StoreCookieBridge::COOKIE);
        if (!is_string($cookie) || $cookie === '') {
            // Unauthenticated store auth endpoints (login/register) still benefit
            // from first-party Origin when the FE is on an allowlisted host.
            if (!PublicCors::isFirstPartyRequest($request) && $this->hasBrowserOrigin($request)) {
                return ApiResponse::error('csrf_origin', 'Cross-site store request blocked.', [], 403);
            }

            return null;
        }

        if (!PublicCors::isFirstPartyRequest($request)) {
            return ApiResponse::error('csrf_origin', 'Cross-site store request blocked.', [], 403);
        }

        return null;
    }

    private function hasBrowserOrigin(Request $request): bool
    {
        return trim((string) $request->headers->get('Origin', '')) !== ''
            || trim((string) $request->headers->get('Referer', '')) !== '';
    }
}

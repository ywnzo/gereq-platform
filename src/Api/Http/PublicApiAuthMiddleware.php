<?php

declare(strict_types=1);

namespace Gereq\Api\Http;

use Gereq\Api\ApiResponse;
use Gereq\Api\Auth\PublicApiKey;
use Gereq\Http\Middleware\MiddlewareInterface;
use Gereq\Http\PublicCors;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Requires site public API key (Bearer grq_pub_* or X-Api-Key) on /api/public/*. */
final class PublicApiAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PublicApiKey $publicKey)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $path = $request->getPathInfo();
        if (!PublicCors::isPublicApiPath($path)) {
            return $next($request);
        }

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $next($request);
        }

        if (!$this->publicKey->isConfigured()) {
            return $this->cors(
                $request,
                ApiResponse::error(
                    'misconfigured',
                    'Public API key is not configured (API_PUBLIC_KEY).',
                    [],
                    503,
                ),
            );
        }

        $provided = $this->publicKey->extractFromRequest($request);
        if (!$this->publicKey->matches($provided)) {
            return $this->cors(
                $request,
                ApiResponse::unauthorized('Missing or invalid public API key.'),
            );
        }

        $request->attributes->set('_api_public_key', true);

        return $next($request);
    }

    private function cors(Request $request, Response $response): Response
    {
        return PublicCors::apply($response, $request, 'GET, POST, PATCH, PUT, DELETE, OPTIONS');
    }
}

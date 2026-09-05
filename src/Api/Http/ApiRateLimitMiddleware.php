<?php

declare(strict_types=1);

namespace Gereq\Api\Http;

use Gereq\Api\ApiResponse;
use Gereq\Config\Repository;
use Gereq\Http\Middleware\MiddlewareInterface;
use Gereq\Security\RateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Repository $config,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return $next($request);
        }

        $isWrite = in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $limit = $isWrite
            ? (int) $this->config->get('api.rate_limit.write', 120)
            : (int) $this->config->get('api.rate_limit.standard', 600);
        $window = (int) $this->config->get('api.rate_limit.window', 60);

        $key = 'api:' . ($request->attributes->get('_api_token')?->id() ?? $request->getClientIp() ?? 'anon');
        // Token not yet set before auth — use IP for pre-auth limit key
        if ($request->attributes->get('_api_token') === null) {
            $auth = (string) $request->headers->get('Authorization', '');
            $key = 'api:' . ($auth !== '' ? hash('sha256', $auth) : ($request->getClientIp() ?? 'anon'));
        }

        $ok = $this->limiter->hit($key . ($isWrite ? ':w' : ':r'), $limit, $window);
        if (!$ok) {
            $response = ApiResponse::error('rate_limited', 'Too many requests.', [], 429);
            $response->headers->set('RateLimit-Limit', (string) $limit);
            $response->headers->set('RateLimit-Remaining', '0');
            $response->headers->set('RateLimit-Reset', (string) (time() + $window));

            return $response;
        }

        $response = $next($request);
        $response->headers->set('RateLimit-Limit', (string) $limit);
        $response->headers->set('RateLimit-Remaining', (string) max(0, $limit - 1));
        $response->headers->set('RateLimit-Reset', (string) (time() + $window));

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Api\Http;

use Gereq\Http\Middleware\MiddlewareInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $id = (string) $request->headers->get('X-Request-ID', '');
        if ($id === '') {
            $id = 'req_' . Uuid::uuid7()->toString();
        }
        $request->attributes->set('correlation_id', $id);
        $request->attributes->set('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        return $response;
    }
}

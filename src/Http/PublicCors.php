<?php

declare(strict_types=1);

namespace Gereq\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shared CORS for public storefront APIs — never reflect arbitrary Origin. */
final class PublicCors
{
    public static function apply(Response $response, ?Request $request, string $methods = 'GET, OPTIONS'): Response
    {
        $allowedRaw = trim((string) env_value('FRONTEND_ORIGIN', ''));
        $requestOrigin = trim((string) ($request?->headers->get('Origin') ?? ''));

        if ($allowedRaw === '' || $allowedRaw === '*') {
            // Explicit wildcard only when configured; do not echo client Origin.
            $response->headers->set('Access-Control-Allow-Origin', '*');
        } else {
            $allowed = array_values(array_filter(array_map(
                static fn (string $v): string => trim($v),
                explode(',', $allowedRaw),
            )));
            if ($requestOrigin !== '' && in_array($requestOrigin, $allowed, true)) {
                $response->headers->set('Access-Control-Allow-Origin', $requestOrigin);
                $response->headers->set('Vary', 'Origin');
            } elseif ($allowed !== []) {
                $response->headers->set('Access-Control-Allow-Origin', $allowed[0]);
                $response->headers->set('Vary', 'Origin');
            }
        }

        $response->headers->set('Access-Control-Allow-Methods', $methods);
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}

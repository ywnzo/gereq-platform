<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Publish\StagingSignal;
use Gereq\Publish\StagingStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expose pending staging count on admin responses so the topbar can refresh without reload.
 */
final class StagingCountMiddleware implements MiddlewareInterface
{
    public const HEADER_COUNT = 'X-Gereq-Staging-Count';
    public const HEADER_REVISION = 'X-Gereq-Staging-Revision';

    public function __construct(
        private readonly StagingStore $store,
        private readonly StagingSignal $signal,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/admin') && !str_starts_with($path, '/publish')) {
            return $response;
        }

        try {
            $count = $this->store->count();
        } catch (\Throwable) {
            $count = 0;
        }

        $response->headers->set(self::HEADER_COUNT, (string) $count);
        $response->headers->set(self::HEADER_REVISION, (string) $this->signal->revision());

        return $response;
    }
}

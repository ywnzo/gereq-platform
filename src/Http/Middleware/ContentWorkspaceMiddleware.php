<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Publish\ContentReadContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin (and non-public) requests merge staging; /api/public/* stays production-only.
 */
final class ContentWorkspaceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContentReadContext $context)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/api/public')) {
            $this->context->useProduction();
        } else {
            $this->context->useStaging();
        }

        return $next($request);
    }
}

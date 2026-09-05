<?php

declare(strict_types=1);

namespace Gereq\Api;

use Gereq\Http\Middleware\MiddlewareInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Marker for inbound webhook providers. */
interface InboundWebhookProviderInterface
{
    public function name(): string;

    public function verify(Request $request): bool;

    public function handle(Request $request): Response;
}

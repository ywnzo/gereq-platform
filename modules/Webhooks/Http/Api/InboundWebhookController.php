<?php

declare(strict_types=1);

namespace Modules\Webhooks\Http\Api;

use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Modules\Webhooks\Application\InboundWebhookRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public inbound webhook endpoint: POST /api/v1/webhooks/inbound/{provider} */
final class InboundWebhookController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function handle(Request $request, string $provider): Response
    {
        if (!$this->capabilities->has('webhooks')) {
            return ApiResponse::error('not_found', 'Webhooks capability unavailable', [], 404);
        }
        $registry = $this->capabilities->get('webhooks');
        if (!$registry instanceof InboundWebhookRegistry) {
            return ApiResponse::error('not_found', 'Inbound registry missing', [], 404);
        }
        $handler = $registry->get($provider);
        if ($handler === null) {
            return ApiResponse::error('not_found', 'Unknown inbound provider', [], 404);
        }
        if (!$handler->verify($request)) {
            return ApiResponse::error('unauthorized', 'Invalid signature', [], 401);
        }

        return $handler->handle($request);
    }
}

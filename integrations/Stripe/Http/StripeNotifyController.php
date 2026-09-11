<?php

declare(strict_types=1);

namespace Integrations\Stripe\Http;

use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Integrations\Stripe\Application\StripeInboundProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class StripeNotifyController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly StripeInboundProvider $inbound,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function notify(Request $request): Response
    {
        if (!$this->inbound->verify($request)) {
            return ApiResponse::error('unauthorized', 'Invalid Stripe signature', [], 401);
        }

        return $this->inbound->handle($request);
    }
}

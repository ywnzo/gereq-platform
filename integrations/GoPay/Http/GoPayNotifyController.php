<?php

declare(strict_types=1);

namespace Integrations\GoPay\Http;

use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\RateLimiter;
use Integrations\GoPay\Application\GoPayInboundProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class GoPayNotifyController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly GoPayInboundProvider $inbound,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function notify(Request $request): Response
    {
        $ip = (string) ($request->getClientIp() ?? 'anon');
        if (!$this->limiter->hit('gopay-notify:' . $ip, 60, 60)) {
            return ApiResponse::error('rate_limited', 'Too many GoPay notify requests.', [], 429);
        }

        return $this->inbound->handle($request);
    }
}

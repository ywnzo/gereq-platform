<?php

declare(strict_types=1);

namespace Integrations\GoPay\Http;

use Gereq\Http\Controller;
use Gereq\Http\Router;
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
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function notify(Request $request): Response
    {
        return $this->inbound->handle($request);
    }
}

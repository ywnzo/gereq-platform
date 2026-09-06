<?php

declare(strict_types=1);

namespace Integrations\Comgate\Http;

use Gereq\Http\Controller;
use Gereq\Http\Router;
use Integrations\Comgate\Application\ComgateInboundProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public Comgate notify (no Bearer). Same path as before for BC. */
final class ComgateNotifyController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly ComgateInboundProvider $inbound,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function notify(Request $request): Response
    {
        return $this->inbound->handle($request);
    }
}

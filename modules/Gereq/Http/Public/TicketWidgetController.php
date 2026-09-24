<?php

declare(strict_types=1);

namespace Modules\Gereq\Http\Public;

use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Gereq\Application\Ticketing\TicketService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Gereq\Http\Exception\HttpException;
use Twig\Environment;

final class TicketWidgetController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly TicketService $tickets,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function widget(Request $request): Response
    {
        try {
            $key = (string) $request->query->get('key', '');
            $origin = (string) $request->query->get('origin', '');
            $site = $this->tickets->findActiveSite($key);
            $this->tickets->assertOrigin($site, $origin, $request);

            return $this->render('@gereq/tickets/widget.html.twig', [
                'site' => $site,
                'key' => $key,
                'origin' => $origin,
                'pageUrl' => (string) $request->query->get('page', ''),
                'primaryColor' => $site->primaryColor(),
                'welcome' => $site->agentsOnline()
                    ? $site->welcomeMessage()
                    : ($site->offlineMessage() ?: $site->welcomeMessage()),
                'offline' => !$site->agentsOnline(),
                'siteName' => $site->name(),
            ]);
        } catch (HttpException $e) {
            return new Response('Forbidden', $e->status());
        }
    }
}

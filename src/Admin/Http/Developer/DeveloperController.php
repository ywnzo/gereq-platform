<?php

declare(strict_types=1);

namespace Gereq\Admin\Http\Developer;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Api\ApiRegistry;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventRegistry;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DeveloperController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly ApiRegistry $api,
        private readonly EventRegistry $events,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function api(): Response
    {
        $grouped = [];
        foreach ($this->api->allRoutes() as $route) {
            $grouped[$route->module][] = $route;
        }
        ksort($grouped);

        return $this->page('admin/developer/api/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.api.title'),
            'modules' => $grouped,
            'scopes' => $this->api->scopes(),
        ]);
    }

    public function events(): Response
    {
        return $this->page('admin/developer/events/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.events.title'),
            'groups' => $this->events->groupedByModule(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}

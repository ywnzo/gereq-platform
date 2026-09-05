<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DashboardController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly Dashboard $dashboard,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function home(): Response
    {
        return $this->redirect('admin.login');
    }

    public function index(): Response
    {
        $user = $this->auth->user();
        $request = $this->request();

        return $this->render('admin/dashboard.html.twig', [
            'pageTitle' => $this->translator->trans('nav.overview'),
            'menu' => $this->menu->tree($user, $this->router, $request),
            'commands' => $this->palette->all($user),
            'widgets' => $this->dashboard->grouped($user),
            'gridItems' => $this->dashboard->gridItems($user),
            'user' => $user,
        ]);
    }
}

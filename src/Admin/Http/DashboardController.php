<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Audit\Auditor;
use Gereq\Hook\HookManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Http\Storefront;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly Auditor $auditor,
        private readonly Menu $menu,
        private readonly Dashboard $dashboard,
        private readonly CommandPalette $palette,
        private readonly HookManager $hooks,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function home(): Response
    {
        $page = Storefront::html($this->request());
        if ($page !== null) {
            return $page;
        }

        return $this->redirect('admin.login');
    }

    public function index(): Response
    {
        $user = $this->auth->user();
        $request = $this->request();
        $this->hooks->doAction('admin.dashboard.warmup');
        $editing = $request?->query->get('edit') === '1' && ($user?->can('settings.manage') ?? false);

        return $this->render('admin/dashboard.html.twig', [
            'pageTitle' => $this->translator->trans('nav.overview'),
            'menu' => $this->menu->tree($user, $this->router, $request),
            'commands' => $this->palette->all($user),
            'widgets' => $this->dashboard->grouped($user, $editing),
            'gridItems' => $this->dashboard->gridItems($user, $editing),
            'editing' => $editing,
            'dashboardCatalog' => $editing ? $this->dashboard->catalog($user) : [],
            'user' => $user,
        ]);
    }
    public function saveLayout(Request $request): Response
    {
        $data = $this->validate($request, 'dashboard.layout.save');
        $items = json_decode((string) $data['dashboard_layout'], true);
        if (!is_array($items) || !array_is_list($items)) {
            throw new \Gereq\Validation\ValidationException(['dashboard_layout' => 'validation.json']);
        }
        $user = $this->auth->user();
        if ($user === null) {
            $this->abort(403);
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \Gereq\Validation\ValidationException(['dashboard_layout' => 'validation.json']);
            }
        }
        $this->dashboard->saveVisibleLayout($items, $user);
        $this->auditor->log('dashboard.layout.updated', 'settings', null);
        $this->flash('success', $this->translator->trans('settings.saved'));

        return $this->redirect('admin.dashboard');
    }
}

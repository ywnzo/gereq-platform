<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\ModuleManager;
use Gereq\User\Entity\User;
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
        private readonly ModuleManager $modules,
        private readonly EntityManagerInterface $em,
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

        $moduleCount = count($this->modules->enabledIds());
        $userCount = (int) $this->em->getRepository(User::class)->createQueryBuilder('u')->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
        $logs = $this->em->getRepository(\Gereq\Audit\AuditLog::class)->findBy([], ['createdAt' => 'DESC'], 12);

        $stats = [
            'modules' => $moduleCount,
            'users' => $userCount,
            'jobs' => 0,
        ];

        if (class_exists(\Modules\Orders\Domain\Order::class)) {
            $orderRepo = $this->em->getRepository(\Modules\Orders\Domain\Order::class);
            $stats['orders'] = (int) $orderRepo->createQueryBuilder('o')->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();
            $stats['open_orders'] = (int) $orderRepo->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->where('o.status IN (:statuses)')
                ->setParameter('statuses', ['draft', 'confirmed'])
                ->getQuery()
                ->getSingleScalarResult();
            $since = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
            $revenue = (float) $orderRepo->createQueryBuilder('o')
                ->select('COALESCE(SUM(o.total), 0)')
                ->where('o.createdAt >= :since')
                ->andWhere('o.status NOT IN (:cancelled)')
                ->setParameter('since', $since)
                ->setParameter('cancelled', ['cancelled'])
                ->getQuery()
                ->getSingleScalarResult();
            $stats['revenue'] = number_format($revenue, 0, ',', ' ');
            $stats['currency'] = 'CZK';
        }

        $shortcuts = [];
        if ($user !== null) {
            $candidates = [
                ['orders.view', 'nav.orders', 'admin.orders.index', 'cart', 'admin.dashboard.hint.orders'],
                ['products.view', 'nav.products', 'admin.products.index', 'package', 'admin.dashboard.hint.products'],
                ['customers.view', 'nav.customers', 'admin.customers.index', 'users', 'admin.dashboard.hint.customers'],
                ['invoices.view', 'nav.invoices', 'admin.invoices.index', 'receipt', 'admin.dashboard.hint.invoices'],
                ['payments.view', 'nav.payments', 'admin.payments.methods.index', 'credit-card', 'admin.dashboard.hint.payments'],
                ['settings.manage', 'nav.settings', 'admin.settings.index', 'settings', 'admin.dashboard.hint.settings'],
            ];
            foreach ($candidates as [$perm, $label, $route, $icon, $hint]) {
                if (!$user->can($perm)) {
                    continue;
                }
                try {
                    $shortcuts[] = [
                        'label' => $label,
                        'url' => $this->router->url($route),
                        'icon' => $icon,
                        'hint' => $hint,
                    ];
                } catch (\Throwable) {
                    // route may be missing when module disabled
                }
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'pageTitle' => $this->translator->trans('nav.overview'),
            'menu' => $this->menu->tree($user, $this->router, $request),
            'commands' => $this->palette->all($user),
            'widgets' => $this->dashboard->widgets($user),
            'stats' => $stats,
            'shortcuts' => $shortcuts,
            'activity' => $logs,
            'user' => $user,
        ]);
    }
}

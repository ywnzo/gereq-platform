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
        $logs = $this->em->getRepository(\Gereq\Audit\AuditLog::class)->findBy([], ['createdAt' => 'DESC'], 8);

        return $this->render('admin/dashboard.html.twig', [
            'pageTitle' => 'Přehled',
            'menu' => $this->menu->tree($user, $this->router, $request),
            'commands' => $this->palette->all($user),
            'widgets' => $this->dashboard->widgets($user),
            'stats' => [
                'modules' => $moduleCount,
                'users' => $userCount,
                'jobs' => 0,
            ],
            'activity' => $logs,
            'user' => $user,
        ]);
    }
}

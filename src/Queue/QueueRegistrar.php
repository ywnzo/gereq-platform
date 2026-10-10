<?php

declare(strict_types=1);

namespace Gereq\Queue;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Http\Router;
use Gereq\Queue\Application\QueueHealthChecker;
use Gereq\Queue\Application\Widgets\FailedJobsMetricWidget;
use Gereq\Queue\Application\Widgets\FailedJobsWidget;
use Gereq\Queue\Http\QueueFailedController;
use Gereq\Queue\Http\QueueRunController;
use Gereq\User\PermissionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Core registration for job-queue administration (runs, failed_jobs UI, health badge, widgets).
 */
final class QueueRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly Menu $menu,
        private readonly PermissionRegistry $permissions,
        private readonly Dashboard $dashboard,
        private readonly CommandPalette $palette,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly QueueHealthChecker $health,
    ) {
    }

    public function register(): void
    {
        $this->permissions();
        $this->routes();
        $this->menu();
        $this->dashboard();
        $this->commands();
    }

    private function permissions(): void
    {
        $this->permissions->register('queues.view', 'perm.queues.view', 'perm.group.queues');
        $this->permissions->register('queues.manage', 'perm.queues.manage', 'perm.group.queues');
    }

    private function routes(): void
    {
        $this->router->group('/admin/queues', function (Router $routes): void {
            $routes->get('/runs', [QueueRunController::class, 'index'])
                ->name('admin.queues.runs')
                ->permission('queues.view');
            $routes->get('/runs/{id}', [QueueRunController::class, 'show'])
                ->name('admin.queues.runs.show')
                ->permission('queues.view')
                ->where('id', '[0-9a-fA-F-]{36}');

            $routes->get('', [QueueFailedController::class, 'index'])
                ->name('admin.queues.index')
                ->permission('queues.view');
            $routes->get('/{id}', [QueueFailedController::class, 'show'])
                ->name('admin.queues.show')
                ->permission('queues.view')
                ->where('id', '[0-9a-fA-F-]{36}');
            $routes->post('/{id}/retry', [QueueFailedController::class, 'retry'])
                ->name('admin.queues.retry')
                ->permission('queues.manage')
                ->where('id', '[0-9a-fA-F-]{36}');
            $routes->post('/{id}/forget', [QueueFailedController::class, 'forget'])
                ->name('admin.queues.forget')
                ->permission('queues.manage')
                ->where('id', '[0-9a-fA-F-]{36}');
            $routes->post('/prune', [QueueFailedController::class, 'prune'])
                ->name('admin.queues.prune')
                ->permission('queues.manage');
        }, ['middleware' => ['auth']]);
    }

    private function menu(): void
    {
        $badge = null;
        try {
            $badge = $this->health->status()->menuBadge();
        } catch (\Throwable) {
            $badge = null;
        }

        $this->menu->add(new MenuItem(
            id: 'queues',
            label: 'nav.queues',
            icon: 'inbox',
            route: 'admin.queues.runs',
            permission: 'queues.view',
            order: 95,
            badge: $badge,
            group: 'Systém',
        ));
        $this->menu->add(new MenuItem(
            id: 'queues.runs',
            label: 'nav.queues.runs',
            route: 'admin.queues.runs',
            permission: 'queues.view',
            parent: 'queues',
            order: 10,
        ));
        $this->menu->add(new MenuItem(
            id: 'queues.failed',
            label: 'nav.queues.failed',
            route: 'admin.queues.index',
            permission: 'queues.view',
            parent: 'queues',
            order: 20,
        ));
        $this->menu->add(new MenuItem(
            id: 'queues.schedule',
            label: 'nav.queues.schedule',
            route: 'admin.queues.schedule',
            permission: 'queues.view',
            parent: 'queues',
            order: 30,
        ));
    }

    private function dashboard(): void
    {
        $this->dashboard->addWidget(new FailedJobsMetricWidget(
            $this->twig,
            $this->health,
            $this->translator,
            $this->router,
        ));
        $this->dashboard->addWidget(new FailedJobsWidget(
            $this->twig,
            $this->health,
            $this->translator,
            $this->router,
        ));
    }

    private function commands(): void
    {
        $this->palette->add(new PaletteCommand(
            id: 'queues.runs',
            label: 'palette.queues.runs',
            url: '/admin/queues/runs',
            icon: 'inbox',
            permission: 'queues.view',
            keywords: ['queue', 'fronta', 'jobs', 'ulohy', 'runs', 'behy', 'history', 'historie'],
        ));
        $this->palette->add(new PaletteCommand(
            id: 'queues.index',
            label: 'palette.queues.index',
            url: '/admin/queues',
            icon: 'inbox',
            permission: 'queues.view',
            keywords: ['queue', 'fronta', 'jobs', 'ulohy', 'failed', 'selhalo', 'error', 'chyba'],
        ));
    }
}

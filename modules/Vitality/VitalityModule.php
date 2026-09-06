<?php

declare(strict_types=1);

namespace Modules\Vitality;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Vitality\Application\VitalityMonitor;
use Modules\Vitality\Http\VitalityController;

final class VitalityModule implements ModuleInterface
{
    public function id(): string
    {
        return 'vitality';
    }

    public function version(): string
    {
        return '1.0.3';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('vitality.view', 'perm.vitality.view', 'perm.group.vitality');
        $context->permissions()->register('vitality.manage', 'perm.vitality.manage', 'perm.group.vitality');

        $context->router()->group('/admin/vitality', function (Router $routes): void {
            $routes->get('', [VitalityController::class, 'index'])
                ->name('admin.vitality.index')
                ->permission('vitality.view');
            $routes->get('/logs/{id}', [VitalityController::class, 'show'])
                ->name('admin.vitality.show')
                ->permission('vitality.view');
            $routes->post('/reset', [VitalityController::class, 'reset'])
                ->name('admin.vitality.reset')
                ->permission('vitality.manage');
        }, ['middleware' => ['auth']]);
    }

    public function boot(ModuleContext $context): void
    {
        $badge = VitalityMonitor::heartBadgeHtml('green');
        try {
            /** @var VitalityMonitor $monitor */
            $monitor = $context->get(VitalityMonitor::class);
            $translator = $context->get(\Symfony\Contracts\Translation\TranslatorInterface::class);
            $status = $monitor->overallStatus();
            $badge = $monitor->statusBadgeHtml(
                $status,
                $translator->trans('vitality.status.' . $status),
            );
        } catch (\Throwable) {
            // Tables may not exist until module update.
        }

        $context->menu()->add(new MenuItem(
            id: 'vitality',
            label: 'nav.vitality',
            icon: 'heart',
            route: 'admin.vitality.index',
            permission: 'vitality.view',
            order: 92,
            badge: $badge,
            group: 'Systém',
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'vitality.index',
            label: 'palette.vitality.index',
            url: '/admin/vitality',
            icon: 'heart',
            permission: 'vitality.view',
            keywords: ['vitality', 'api', 'latency', 'performance', 'monitor'],
        ));
    }
}

<?php

declare(strict_types=1);

namespace Gereq\Schedule;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Http\Router;
use Gereq\Schedule\Http\ScheduleController;

/**
 * Core schedule registration: currency FX sync + admin list under /admin/queues/schedule.
 */
final class ScheduleRegistrar
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly Router $router,
        private readonly CommandPalette $palette,
    ) {
    }

    public function register(): void
    {
        $this->schedule->command('currency:rates:sync')
            ->dailyAt('07:00')
            ->timezone('Europe/Prague')
            ->async()
            ->name('currency.rates.sync')
            ->description('Download daily ČNB exchange rates')
            ->withoutOverlapping();

        $this->router->group('/admin/queues', function (Router $routes): void {
            $routes->get('/schedule', [ScheduleController::class, 'index'])
                ->name('admin.queues.schedule')
                ->permission('queues.view');
            $routes->post('/schedule/{name}/run', [ScheduleController::class, 'run'])
                ->name('admin.queues.schedule.run')
                ->permission('queues.manage')
                ->where('name', '[a-zA-Z0-9._-]{1,120}');
            $routes->post('/schedule/{name}/pause', [ScheduleController::class, 'pause'])
                ->name('admin.queues.schedule.pause')
                ->permission('queues.manage')
                ->where('name', '[a-zA-Z0-9._-]{1,120}');
            $routes->post('/schedule/{name}/resume', [ScheduleController::class, 'resume'])
                ->name('admin.queues.schedule.resume')
                ->permission('queues.manage')
                ->where('name', '[a-zA-Z0-9._-]{1,120}');
        }, ['middleware' => ['auth']]);

        $this->palette->add(new PaletteCommand(
            id: 'queues.schedule',
            label: 'palette.queues.schedule',
            url: '/admin/queues/schedule',
            icon: 'clock',
            permission: 'queues.view',
            keywords: ['schedule', 'cron', 'scheduler', 'plan', 'ulohy', 'planovane'],
        ));
    }
}

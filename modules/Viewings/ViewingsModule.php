<?php

declare(strict_types=1);

namespace Modules\Viewings;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Viewings\Application\ViewingsProvider;
use Modules\Viewings\Application\Widgets\ViewingsShortcutWidget;
use Modules\Viewings\Http\Api\ViewingApiController;
use Modules\Viewings\Http\Public\PublicViewingsController;
use Modules\Viewings\Http\ViewingController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ViewingsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'viewings';
    }

    public function version(): string
    {
        return '1.0.3';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('viewings.view', 'perm.viewings.view', 'perm.group.viewings');
        $context->permissions()->register('viewings.manage', 'perm.viewings.manage', 'perm.group.viewings');
        $context->capabilities()->register('viewings', $this->id(), new ViewingsProvider($context->get(EntityManagerInterface::class)));

        $context->router()->group('/admin/viewings', function (Router $routes): void {
            $routes->get('', [ViewingController::class, 'index'])->name('admin.viewings.index')->permission('viewings.view');
            $routes->get('/create', [ViewingController::class, 'create'])->name('admin.viewings.create')->permission('viewings.manage');
            $routes->post('', [ViewingController::class, 'store'])->name('admin.viewings.store')->permission('viewings.manage');
            $routes->get('/{id}', [ViewingController::class, 'show'])->name('admin.viewings.show')->permission('viewings.view');
            $routes->get('/{id}/edit', [ViewingController::class, 'edit'])->name('admin.viewings.edit')->permission('viewings.manage');
            $routes->post('/{id}', [ViewingController::class, 'update'])->name('admin.viewings.update')->permission('viewings.manage');
            $routes->post('/{id}/status', [ViewingController::class, 'updateStatus'])->name('admin.viewings.status')->permission('viewings.manage');
            $routes->post('/{id}/delete', [ViewingController::class, 'destroy'])->name('admin.viewings.destroy')->permission('viewings.manage');
        }, ['middleware' => ['auth']]);

        $public = [PublicViewingsController::class, 'options'];
        $context->router()->add('/api/public/viewings', ['OPTIONS'], $public)->name('api.public.viewings.options');
        $context->router()->post('/api/public/viewings', [PublicViewingsController::class, 'store'])
            ->name('api.public.viewings.store');

        $context->api()->registerKnownScope('viewings.read');
        $context->api()->registerKnownScope('viewings.write');
        $context->api()->module('viewings', function (ApiModule $api): void {
            $api->get('/', [ViewingApiController::class, 'index'])->scope('viewings.read');
            $api->post('/', [ViewingApiController::class, 'store'])->scope('viewings.write');
            $api->get('/{id}', [ViewingApiController::class, 'show'])->scope('viewings.read');
            $api->patch('/{id}', [ViewingApiController::class, 'update'])->scope('viewings.write');
            $api->delete('/{id}', [ViewingApiController::class, 'destroy'])->scope('viewings.write');
        });

        foreach ([
            'viewing.created' => 'Prohlídka vytvořena',
            'viewing.updated' => 'Prohlídka upravena',
            'viewing.status_changed' => 'Změněn stav prohlídky',
            'viewing.deleted' => 'Prohlídka smazána',
            'viewing.requested' => 'Veřejná žádost o prohlídku',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true)
            );
        }

        $v = $context->validation();
        $v->ruleset('viewings.save', [
            'listing_id' => 'required|max:36',
            'customer_id' => 'nullable|max:36',
            'broker_id' => 'nullable|max:36',
            'status' => 'nullable|in:requested,scheduled,confirmed,completed,cancelled,no_show',
            'scheduled_at' => 'nullable|max:64',
            'contact_name' => 'required|min:1|max:190',
            'contact_email' => 'nullable|email|max:190',
            'contact_phone' => 'nullable|max:64',
            'note' => 'nullable|max:20000',
        ]);
        $context->forms()->entity('viewings.save', \Modules\Viewings\Domain\Viewing::class);
        $v->ruleset('viewings.status', [
            'status' => 'required|in:requested,scheduled,confirmed,completed,cancelled,no_show',
        ]);
        $v->ruleset('viewings.public_request', [
            'listing_id' => 'required|max:36',
            'contact_name' => 'required|min:1|max:190',
            'contact_email' => 'nullable|email|max:190',
            'contact_phone' => 'nullable|max:64',
            'scheduled_at' => 'nullable|max:64',
            'note' => 'nullable|max:20000',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new ViewingsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'viewings',
            label: 'nav.viewings',
            icon: 'calendar',
            route: 'admin.viewings.index',
            permission: 'viewings.view',
            order: 28,
            group: 'Reality',
        ));
        $context->menu()->add(new MenuItem(
            id: 'viewings.create',
            label: 'nav.viewings.create',
            route: 'admin.viewings.create',
            permission: 'viewings.manage',
            parent: 'viewings',
            order: 29,
            hidden: true,
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'viewings.index',
            label: 'palette.viewings.index',
            url: '/admin/viewings',
            icon: 'calendar',
            permission: 'viewings.view',
            keywords: ['prohlídka', 'viewing', 'schůzka', 'reality'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'viewings.create',
            label: 'palette.viewings.create',
            url: '/admin/viewings/create',
            icon: 'calendar',
            permission: 'viewings.manage',
            keywords: ['prohlídka', 'nová', 'viewing'],
        ));
    }
}

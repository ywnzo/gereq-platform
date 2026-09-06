<?php

declare(strict_types=1);

namespace Modules\Portals;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Portals\Application\Drivers\BezrealitkyDriver;
use Modules\Portals\Application\Drivers\CustomPortalDriver;
use Modules\Portals\Application\Drivers\IdnesDriver;
use Modules\Portals\Application\Drivers\SrealityDriver;
use Modules\Portals\Application\PortalsProvider;
use Modules\Portals\Application\Widgets\PortalsShortcutWidget;
use Modules\Portals\Http\Api\PortalApiController;
use Modules\Portals\Http\PortalController;
use Modules\Portals\Http\ProviderController;
use Modules\Portals\Http\PublicationController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PortalsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'portals';
    }

    public function version(): string
    {
        return '1.3.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('portals.view', 'perm.portals.view', 'perm.group.portals');
        $context->permissions()->register('portals.manage', 'perm.portals.manage', 'perm.group.portals');

        $context->capabilities()->register(
            'portals',
            $this->id(),
            new PortalsProvider(
                $context->get(EntityManagerInterface::class),
                new SrealityDriver(),
                new IdnesDriver(),
                new BezrealitkyDriver(),
                new CustomPortalDriver(),
            ),
        );

        // Číselník poskytovatelů — před /admin/portals/{id}
        $context->router()->group('/admin/portal-providers', function (Router $routes): void {
            $routes->get('', [ProviderController::class, 'index'])->name('admin.portals.providers.index')->permission('portals.view');
            $routes->get('/create', [ProviderController::class, 'create'])->name('admin.portals.providers.create')->permission('portals.manage');
            $routes->post('', [ProviderController::class, 'store'])->name('admin.portals.providers.store')->permission('portals.manage');
            $routes->get('/{id}', [ProviderController::class, 'show'])->name('admin.portals.providers.show')->permission('portals.view');
            $routes->get('/{id}/edit', [ProviderController::class, 'edit'])->name('admin.portals.providers.edit')->permission('portals.manage');
            $routes->post('/{id}', [ProviderController::class, 'update'])->name('admin.portals.providers.update')->permission('portals.manage');
            $routes->post('/{id}/toggle', [ProviderController::class, 'toggle'])->name('admin.portals.providers.toggle')->permission('portals.manage');
            $routes->post('/{id}/delete', [ProviderController::class, 'destroy'])->name('admin.portals.providers.destroy')->permission('portals.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/portals', function (Router $routes): void {
            $routes->get('', [PortalController::class, 'index'])->name('admin.portals.index')->permission('portals.view');
            $routes->get('/create', [PortalController::class, 'create'])->name('admin.portals.create')->permission('portals.manage');
            $routes->post('', [PortalController::class, 'store'])->name('admin.portals.store')->permission('portals.manage');
            $routes->post('/bulk', [PortalController::class, 'bulk'])->name('admin.portals.bulk')->permission('portals.manage');
            $routes->get('/{id}', [PortalController::class, 'show'])->name('admin.portals.show')->permission('portals.view');
            $routes->get('/{id}/edit', [PortalController::class, 'edit'])->name('admin.portals.edit')->permission('portals.manage');
            $routes->post('/{id}', [PortalController::class, 'update'])->name('admin.portals.update')->permission('portals.manage');
            $routes->post('/{id}/toggle', [PortalController::class, 'toggle'])->name('admin.portals.toggle')->permission('portals.manage');
            $routes->post('/{id}/delete', [PortalController::class, 'destroy'])->name('admin.portals.destroy')->permission('portals.manage');
            $routes->post('/{id}/queue', [PublicationController::class, 'queue'])->name('admin.portals.queue')->permission('portals.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/portals-publications', function (Router $routes): void {
            $routes->get('', [PublicationController::class, 'index'])->name('admin.portals.publications.index')->permission('portals.view');
            $routes->get('/{id}', [PublicationController::class, 'show'])->name('admin.portals.publications.show')->permission('portals.view');
            $routes->post('/{id}/publish', [PublicationController::class, 'markPublished'])->name('admin.portals.publications.publish')->permission('portals.manage');
            $routes->post('/{id}/delete', [PublicationController::class, 'destroy'])->name('admin.portals.publications.destroy')->permission('portals.manage');
        }, ['middleware' => ['auth']]);

        $context->api()->registerKnownScope('portals.read');
        $context->api()->registerKnownScope('portals.write');
        $context->api()->module('portals', function (ApiModule $api): void {
            $api->get('/accounts', [PortalApiController::class, 'accounts'])->scope('portals.read');
            $api->get('/publications', [PortalApiController::class, 'publications'])->scope('portals.read');
            $api->post('/queue', [PortalApiController::class, 'queue'])->scope('portals.write');
        });

        foreach ([
            'portal.account_created' => 'Účet portálu vytvořen',
            'portal.account_updated' => 'Účet portálu upraven',
            'portal.queued' => 'Nabídka zařazena do fronty portálu',
            'portal.published' => 'Nabídka označena jako publikovaná na portálu',
            'portal.publication_deleted' => 'Publikace smazána',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true)
            );
        }

        $v = $context->validation();
        $v->ruleset('portals.account.save', [
            'code' => 'required|min:2|max:64',
            'name' => 'required|min:1|max:190',
            'provider_id' => 'required|max:36',
            'active' => 'nullable',
        ]);
        $v->ruleset('portals.provider.save', [
            'code' => 'required|min:2|max:64',
            'name' => 'required|min:1|max:190',
            'driver' => 'required|in:sreality,idnes,bezrealitky,custom',
            'description' => 'nullable|max:5000',
            'api_base_url' => 'nullable|max:500',
            'openapi_url' => 'nullable|max:500',
            'sort_order' => 'nullable',
            'active' => 'nullable',
        ]);
        $v->ruleset('portals.queue', [
            'listing_id' => 'required|max:36',
            'portal_account_id' => 'required|max:36',
        ]);
        $v->ruleset('portals.bulk', [
            'action' => 'required|in:activate,deactivate,delete',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new PortalsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'portals',
            label: 'nav.portals',
            icon: 'link',
            route: 'admin.portals.index',
            permission: 'portals.view',
            order: 30,
            group: 'Reality',
        ));
        $context->menu()->add(new MenuItem(
            id: 'portals.providers',
            label: 'nav.portals.providers',
            route: 'admin.portals.providers.index',
            permission: 'portals.view',
            parent: 'portals',
            order: 30,
        ));
        $context->menu()->add(new MenuItem(
            id: 'portals.publications',
            label: 'nav.portals.publications',
            route: 'admin.portals.publications.index',
            permission: 'portals.view',
            parent: 'portals',
            order: 31,
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'portals.index',
            label: 'palette.portals.index',
            url: '/admin/portals',
            icon: 'link',
            permission: 'portals.view',
            keywords: ['portál', 'sreality', 'idnes', 'bezrealitky', 'export'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'portals.providers',
            label: 'palette.portals.providers',
            url: '/admin/portal-providers',
            icon: 'link',
            permission: 'portals.view',
            keywords: ['číselník', 'poskytovatel', 'provider', 'sreality'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'portals.publications',
            label: 'palette.portals.publications',
            url: '/admin/portals-publications',
            icon: 'link',
            permission: 'portals.view',
            keywords: ['publikace', 'export', 'portál'],
        ));
    }
}

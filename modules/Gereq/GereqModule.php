<?php

declare(strict_types=1);

namespace Modules\Gereq;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Gereq\Http\Admin\SecuritySiteController;
use Modules\Gereq\Http\Admin\SupportExtrasController;
use Modules\Gereq\Http\Admin\TicketingController;
use Modules\Gereq\Http\Api\GereqApiController;
use Modules\Gereq\Http\Public\EmbedScriptController;
use Modules\Gereq\Http\Public\SecurityApiController;
use Modules\Gereq\Http\Public\TicketApiController;
use Modules\Gereq\Http\Public\TicketWidgetController;

final class GereqModule implements ModuleInterface
{
    public function id(): string
    {
        return 'gereq';
    }

    public function version(): string
    {
        return '1.0.1';
    }

    public function register(ModuleContext $context): void
    {
        // Visible only to privileged admins (superadmin/admin); admin-client excluded in Seeder.
        $context->permissions()->register('gereq.manage', 'perm.gereq.manage', 'perm.group.gereq');

        $this->registerAdminRoutes($context);
        $this->registerPublicRoutes($context);
        $this->registerApi($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'gereq',
            label: 'nav.gereq',
            icon: 'shield',
            route: 'admin.gereq.ticketing.desk',
            permission: 'gereq.manage',
            order: 115,
            group: 'Systém',
        ));
        $context->menu()->add(new MenuItem(
            id: 'gereq.ticketing',
            label: 'nav.gereq.ticketing',
            icon: 'inbox',
            route: 'admin.gereq.ticketing.desk',
            permission: 'gereq.manage',
            parent: 'gereq',
            order: 10,
            group: 'Systém',
        ));
        $context->menu()->add(new MenuItem(
            id: 'gereq.security',
            label: 'nav.gereq.security',
            icon: 'shield',
            route: 'admin.gereq.security.index',
            permission: 'gereq.manage',
            parent: 'gereq',
            order: 20,
            group: 'Systém',
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'gereq.ticketing',
            label: 'palette.gereq.ticketing',
            url: '/admin/gereq/ticketing',
            icon: 'inbox',
            permission: 'gereq.manage',
            keywords: ['ticket', 'ticketing', 'support', 'chat', 'gereq'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'gereq.security',
            label: 'palette.gereq.security',
            url: '/admin/gereq/security',
            icon: 'shield',
            permission: 'gereq.manage',
            keywords: ['security', 'captcha', 'bot', 'gereq'],
        ));
    }

    private function registerAdminRoutes(ModuleContext $context): void
    {
        $context->router()->group('/admin/gereq', function (Router $routes): void {
            $routes->get('/ticketing', [TicketingController::class, 'desk'])->name('admin.gereq.ticketing.desk')->permission('gereq.manage');
            $routes->get('/ticketing/poll/{id}', [TicketingController::class, 'poll'])->name('admin.gereq.ticketing.poll')->permission('gereq.manage');
            $routes->post('/ticketing/{id}/reply', [TicketingController::class, 'reply'])->name('admin.gereq.ticketing.reply')->permission('gereq.manage');
            $routes->post('/ticketing/{id}/status', [TicketingController::class, 'updateStatus'])->name('admin.gereq.ticketing.status')->permission('gereq.manage');

            $routes->get('/ticketing/sites', [TicketingController::class, 'sites'])->name('admin.gereq.ticketing.sites')->permission('gereq.manage');
            $routes->get('/ticketing/sites/create', [TicketingController::class, 'createSite'])->name('admin.gereq.ticketing.sites.create')->permission('gereq.manage');
            $routes->post('/ticketing/sites', [TicketingController::class, 'storeSite'])->name('admin.gereq.ticketing.sites.store')->permission('gereq.manage');
            $routes->get('/ticketing/sites/{id}', [TicketingController::class, 'showSite'])->name('admin.gereq.ticketing.sites.show')->permission('gereq.manage');
            $routes->get('/ticketing/sites/{id}/edit', [TicketingController::class, 'editSite'])->name('admin.gereq.ticketing.sites.edit')->permission('gereq.manage');
            $routes->post('/ticketing/sites/{id}', [TicketingController::class, 'updateSite'])->name('admin.gereq.ticketing.sites.update')->permission('gereq.manage');
            $routes->post('/ticketing/sites/{id}/test-key', [TicketingController::class, 'issueTestKey'])->name('admin.gereq.ticketing.sites.test_key')->permission('gereq.manage');
            $routes->post('/ticketing/sites/{id}/delete', [TicketingController::class, 'destroySite'])->name('admin.gereq.ticketing.sites.destroy')->permission('gereq.manage');

            $routes->get('/ticketing/extras', [SupportExtrasController::class, 'index'])->name('admin.gereq.ticketing.extras')->permission('gereq.manage');
            $routes->post('/ticketing/extras', [SupportExtrasController::class, 'store'])->name('admin.gereq.ticketing.extras.store')->permission('gereq.manage');

            $routes->get('/security', [SecuritySiteController::class, 'index'])->name('admin.gereq.security.index')->permission('gereq.manage');
            $routes->get('/security/create', [SecuritySiteController::class, 'create'])->name('admin.gereq.security.create')->permission('gereq.manage');
            $routes->post('/security', [SecuritySiteController::class, 'store'])->name('admin.gereq.security.store')->permission('gereq.manage');
            $routes->get('/security/{id}', [SecuritySiteController::class, 'show'])->name('admin.gereq.security.show')->permission('gereq.manage');
            $routes->get('/security/{id}/edit', [SecuritySiteController::class, 'edit'])->name('admin.gereq.security.edit')->permission('gereq.manage');
            $routes->post('/security/{id}', [SecuritySiteController::class, 'update'])->name('admin.gereq.security.update')->permission('gereq.manage');
            $routes->post('/security/{id}/rotate', [SecuritySiteController::class, 'rotate'])->name('admin.gereq.security.rotate')->permission('gereq.manage');
            $routes->post('/security/{id}/test-keys', [SecuritySiteController::class, 'issueTestKeys'])->name('admin.gereq.security.test_keys')->permission('gereq.manage');
            $routes->post('/security/{id}/delete', [SecuritySiteController::class, 'destroy'])->name('admin.gereq.security.destroy')->permission('gereq.manage');
        }, ['middleware' => ['auth'], 'module' => 'gereq']);
    }

    private function registerPublicRoutes(ModuleContext $context): void
    {
        $router = $context->router();

        $router->get('/js/gereq-security.js', [EmbedScriptController::class, 'security'])->name('gereq.embed.security');
        $router->get('/js/gereq-monitor.js', [EmbedScriptController::class, 'monitor'])->name('gereq.embed.monitor');
        $router->get('/js/gereq-tickets.js', [EmbedScriptController::class, 'tickets'])->name('gereq.embed.tickets');

        $router->add('/security/api/challenge', ['OPTIONS'], [SecurityApiController::class, 'options'])->name('gereq.security.options.challenge');
        $router->add('/security/api/assess', ['OPTIONS'], [SecurityApiController::class, 'options'])->name('gereq.security.options.assess');
        $router->add('/security/api/beacon', ['OPTIONS'], [SecurityApiController::class, 'options'])->name('gereq.security.options.beacon');
        $router->get('/security/api/challenge', [SecurityApiController::class, 'challenge'])->name('gereq.security.challenge');
        $router->post('/security/api/assess', [SecurityApiController::class, 'assess'])->name('gereq.security.assess');
        $router->post('/security/api/beacon', [SecurityApiController::class, 'beacon'])->name('gereq.security.beacon');
        $router->post('/security/api/verify', [SecurityApiController::class, 'verify'])->name('gereq.security.verify');

        $router->get('/tickets/widget', [TicketWidgetController::class, 'widget'])->name('gereq.tickets.widget');
        $router->add('/tickets/api/session', ['OPTIONS'], [TicketApiController::class, 'options'])->name('gereq.tickets.options.session');
        $router->add('/tickets/api/messages', ['OPTIONS'], [TicketApiController::class, 'options'])->name('gereq.tickets.options.messages');
        $router->post('/tickets/api/session', [TicketApiController::class, 'session'])->name('gereq.tickets.session');
        $router->get('/tickets/api/messages', [TicketApiController::class, 'messages'])->name('gereq.tickets.messages');
        $router->post('/tickets/api/messages', [TicketApiController::class, 'storeMessage'])->name('gereq.tickets.messages.store');
        $router->post('/tickets/api/typing', [TicketApiController::class, 'typing'])->name('gereq.tickets.typing');
        $router->post('/tickets/api/presence', [TicketApiController::class, 'presence'])->name('gereq.tickets.presence');
        $router->post('/tickets/api/rate', [TicketApiController::class, 'rate'])->name('gereq.tickets.rate');
        $router->post('/tickets/api/resolve', [TicketApiController::class, 'resolve'])->name('gereq.tickets.resolve');
        $router->get('/tickets/api/history', [TicketApiController::class, 'history'])->name('gereq.tickets.history');
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('gereq.ticketing.read');
        $context->api()->registerKnownScope('gereq.ticketing.write');
        $context->api()->registerKnownScope('gereq.security.read');
        $context->api()->registerKnownScope('gereq.security.write');
        $context->api()->module('gereq', function (ApiModule $api): void {
            $api->get('/tickets', [GereqApiController::class, 'tickets'])->scope('gereq.ticketing.read')->summary('List tickets');
            $api->get('/tickets/{id}', [GereqApiController::class, 'ticket'])->scope('gereq.ticketing.read')->summary('Get ticket');
            $api->post('/tickets/{id}/messages', [GereqApiController::class, 'reply'])->scope('gereq.ticketing.write')->summary('Agent reply');
            $api->get('/security/sites', [GereqApiController::class, 'securitySites'])->scope('gereq.security.read')->summary('List security sites');
            $api->get('/security/events', [GereqApiController::class, 'securityEvents'])->scope('gereq.security.read')->summary('List security events');
        });
    }
}

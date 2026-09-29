<?php

declare(strict_types=1);

namespace Gereq\Webhooks;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Form\Forms;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Event\EventDefinition;
use Gereq\Event\EventEnvelope;
use Gereq\Event\EventRegistry;
use Gereq\Hook\HookManager;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\User\PermissionRegistry;
use Gereq\Validation\ValidationRegistry;
use Gereq\Webhooks\Application\InboundWebhookRegistry;
use Gereq\Webhooks\Application\WebhookDispatcher;
use Gereq\Webhooks\Application\Widgets\FailedDeliveriesWidget;
use Gereq\Webhooks\Application\Widgets\WebhooksShortcutWidget;
use Gereq\Webhooks\Application\Widgets\WebhookStatsWidget;
use Gereq\Webhooks\Domain\Webhook;
use Gereq\Webhooks\Http\Api\InboundWebhookController;
use Gereq\Webhooks\Http\DeliveryController;
use Gereq\Webhooks\Http\WebhookController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Core registration for outbound/inbound webhooks (formerly modules/Webhooks).
 */
final class WebhooksRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly Menu $menu,
        private readonly PermissionRegistry $permissions,
        private readonly CapabilityRegistry $capabilities,
        private readonly EventRegistry $platformEvents,
        private readonly Dashboard $dashboard,
        private readonly CommandPalette $palette,
        private readonly HookManager $hooks,
        private readonly ValidationRegistry $validation,
        private readonly Forms $forms,
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly InboundWebhookRegistry $inboundRegistry,
        private readonly WebhookDispatcher $dispatcher,
    ) {
    }

    public function register(): void
    {
        $this->permissions();
        $this->capabilities();
        $this->routes();
        $this->menu();
        $this->dashboard();
        $this->commands();
        $this->platformEvents();
        $this->validation();
        $this->hooks();
    }

    private function permissions(): void
    {
        $this->permissions->register('webhooks.view', 'perm.webhooks.view', 'perm.group.webhooks');
        $this->permissions->register('webhooks.manage', 'perm.webhooks.manage', 'perm.group.webhooks');
        $this->permissions->register(
            'webhooks.deliveries.retry',
            'perm.webhooks.deliveries.retry',
            'perm.group.webhooks',
        );
    }

    private function capabilities(): void
    {
        $this->capabilities->register('webhooks', 'core', $this->inboundRegistry);
    }

    private function routes(): void
    {
        $this->router->post('/api/v1/webhooks/inbound/{provider}', [InboundWebhookController::class, 'handle'])
            ->name('api.webhooks.inbound');

        $this->router->group('/admin/developer/webhooks', function (Router $routes): void {
            $routes->get('', [WebhookController::class, 'index'])
                ->name('admin.developer.webhooks.index')
                ->permission('webhooks.view');
            $routes->get('/create', [WebhookController::class, 'create'])
                ->name('admin.developer.webhooks.create')
                ->permission('webhooks.manage');
            $routes->post('', [WebhookController::class, 'store'])
                ->name('admin.developer.webhooks.store')
                ->permission('webhooks.manage');
            $routes->get('/{id}', [WebhookController::class, 'show'])
                ->name('admin.developer.webhooks.show')
                ->permission('webhooks.view');
            $routes->get('/{id}/edit', [WebhookController::class, 'edit'])
                ->name('admin.developer.webhooks.edit')
                ->permission('webhooks.manage');
            $routes->post('/{id}', [WebhookController::class, 'update'])
                ->name('admin.developer.webhooks.update')
                ->permission('webhooks.manage');
            $routes->post('/{id}/delete', [WebhookController::class, 'destroy'])
                ->name('admin.developer.webhooks.destroy')
                ->permission('webhooks.manage');
            $routes->post('/{id}/test', [WebhookController::class, 'test'])
                ->name('admin.developer.webhooks.test')
                ->permission('webhooks.manage');
            $routes->post('/{id}/rotate-secret', [WebhookController::class, 'rotateSecret'])
                ->name('admin.developer.webhooks.rotate_secret')
                ->permission('webhooks.manage');
            $routes->post('/{id}/pause', [WebhookController::class, 'pause'])
                ->name('admin.developer.webhooks.pause')
                ->permission('webhooks.manage');
            $routes->post('/{id}/activate', [WebhookController::class, 'activate'])
                ->name('admin.developer.webhooks.activate')
                ->permission('webhooks.manage');
        }, ['middleware' => ['auth']]);

        $this->router->group('/admin/developer/webhook-deliveries', function (Router $routes): void {
            $routes->get('', [DeliveryController::class, 'index'])
                ->name('admin.developer.webhook-deliveries.index')
                ->permission('webhooks.view');
            $routes->get('/{id}', [DeliveryController::class, 'show'])
                ->name('admin.developer.webhook-deliveries.show')
                ->permission('webhooks.view');
            $routes->post('/{id}/retry', [DeliveryController::class, 'retry'])
                ->name('admin.developer.webhook-deliveries.retry')
                ->permission('webhooks.deliveries.retry');
        }, ['middleware' => ['auth']]);
    }

    private function menu(): void
    {
        $this->menu->add(new MenuItem(
            id: 'developer.webhooks',
            label: 'nav.webhooks',
            icon: 'zap',
            route: 'admin.developer.webhooks.index',
            permission: 'webhooks.view',
            parent: 'developer',
            order: 40,
            group: 'Systém',
        ));
        $this->menu->add(new MenuItem(
            id: 'developer.webhook-deliveries',
            label: 'nav.webhook_deliveries',
            route: 'admin.developer.webhook-deliveries.index',
            permission: 'webhooks.view',
            parent: 'developer',
            order: 50,
            group: 'Systém',
        ));
        $this->menu->add(new MenuItem(
            id: 'developer.webhooks.create',
            label: 'nav.webhooks.create',
            route: 'admin.developer.webhooks.create',
            permission: 'webhooks.manage',
            parent: 'developer.webhooks',
            order: 10,
            hidden: true,
        ));
    }

    private function dashboard(): void
    {
        $this->dashboard->addWidget(new WebhooksShortcutWidget(
            $this->twig,
            $this->translator,
            $this->router,
        ));
        foreach ([WebhookStatsWidget::class, FailedDeliveriesWidget::class] as $widgetClass) {
            $this->dashboard->addWidget(new $widgetClass(
                $this->twig,
                $this->em,
                $this->translator,
                $this->router,
            ));
        }
    }

    private function commands(): void
    {
        $this->palette->add(new PaletteCommand(
            id: 'webhooks.index',
            label: 'palette.webhooks.index',
            url: '/admin/developer/webhooks',
            icon: 'zap',
            permission: 'webhooks.view',
            keywords: ['webhook', 'integrace', 'události'],
        ));
        $this->palette->add(new PaletteCommand(
            id: 'webhooks.create',
            label: 'palette.webhooks.create',
            url: '/admin/developer/webhooks/create',
            icon: 'plus',
            permission: 'webhooks.manage',
            group: 'Akce',
        ));
        $this->palette->add(new PaletteCommand(
            id: 'webhook-deliveries.index',
            label: 'palette.webhooks.deliveries',
            url: '/admin/developer/webhook-deliveries',
            icon: 'zap',
            permission: 'webhooks.view',
            keywords: ['delivery', 'doručení', 'retry'],
        ));
    }

    private function platformEvents(): void
    {
        $this->platformEvents->register(
            new EventDefinition('webhook.test', 'core', 1, 'Testovací událost webhooku', true)
        );
    }

    private function validation(): void
    {
        $this->validation->ruleset('webhooks.save', [
            'name' => 'required|min:2|max:190',
            'url' => 'required|url|max:2048',
            'timeout_seconds' => 'nullable|integer',
        ]);
        $this->forms->entity('webhooks.save', Webhook::class);
    }

    private function hooks(): void
    {
        $dispatcher = $this->dispatcher;
        $this->hooks->addAction(
            'platform.event.dispatched',
            static function (EventEnvelope $envelope, mixed $event = null) use ($dispatcher): void {
                unset($event);
                $dispatcher->dispatch($envelope);
            },
            HookPriority::NORMAL,
        );
    }
}

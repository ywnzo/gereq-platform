<?php

declare(strict_types=1);

namespace Modules\Webhooks;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Event\EventDefinition;
use Gereq\Event\EventEnvelope;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Webhooks\Application\InboundWebhookRegistry;
use Modules\Webhooks\Application\WebhookDispatcher;
use Modules\Webhooks\Http\Api\InboundWebhookController;
use Modules\Webhooks\Http\DeliveryController;
use Modules\Webhooks\Http\WebhookController;

final class WebhooksModule implements ModuleInterface
{
    public function id(): string
    {
        return 'webhooks';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('webhooks.view', 'perm.webhooks.view', 'perm.group.webhooks');
        $context->permissions()->register('webhooks.manage', 'perm.webhooks.manage', 'perm.group.webhooks');
        $context->permissions()->register('webhooks.deliveries.retry', 'perm.webhooks.deliveries.retry', 'perm.group.webhooks');

        $registry = $context->get(InboundWebhookRegistry::class);
        $context->capabilities()->register('webhooks', $this->id(), $registry);

        // Public inbound webhooks for payment/shipping providers
        $context->router()->post('/api/v1/webhooks/inbound/{provider}', [InboundWebhookController::class, 'handle'])
            ->name('api.webhooks.inbound');

        $context->router()->group('/admin/developer/webhooks', function (Router $routes): void {
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

        $context->router()->group('/admin/developer/webhook-deliveries', function (Router $routes): void {
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

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'developer.webhooks',
            label: 'nav.webhooks',
            icon: 'zap',
            route: 'admin.developer.webhooks.index',
            permission: 'webhooks.view',
            order: 72,
            group: 'Developer',
        ));
        $context->menu()->add(new MenuItem(
            id: 'developer.webhook-deliveries',
            label: 'nav.webhook_deliveries',
            route: 'admin.developer.webhook-deliveries.index',
            permission: 'webhooks.view',
            order: 73,
            group: 'Developer',
        ));
        $context->menu()->add(new MenuItem(
            id: 'developer.webhooks.create',
            label: 'nav.webhooks.create',
            route: 'admin.developer.webhooks.create',
            permission: 'webhooks.manage',
            parent: 'developer.webhooks',
            order: 74,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'webhooks.index',
            label: 'palette.webhooks.index',
            url: '/admin/developer/webhooks',
            icon: 'zap',
            permission: 'webhooks.view',
            keywords: ['webhook', 'integrace', 'události'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'webhooks.create',
            label: 'palette.webhooks.create',
            url: '/admin/developer/webhooks/create',
            icon: 'plus',
            permission: 'webhooks.manage',
            group: 'Akce',
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'webhook-deliveries.index',
            label: 'palette.webhooks.deliveries',
            url: '/admin/developer/webhook-deliveries',
            icon: 'zap',
            permission: 'webhooks.view',
            keywords: ['delivery', 'doručení', 'retry'],
        ));

        $context->platformEvents()->register(
            (new EventDefinition('webhook.test', 'webhooks', 1, 'Testovací událost webhooku', true))
        );

        $this->registerValidation($context);
        $this->registerHooks($context);
    }

    private function registerValidation(ModuleContext $context): void
    {
        $context->validation()->ruleset('webhooks.save', [
            'name' => 'required|min:2|max:190',
            'url' => 'required|url|max:2048',
            'timeout_seconds' => 'nullable|integer',
        ]);
    }

    private function registerHooks(ModuleContext $context): void
    {
        $context->hooks()->addAction(
            'platform.event.dispatched',
            static function (EventEnvelope $envelope, mixed $event = null) use ($context): void {
                unset($event);
                /** @var WebhookDispatcher $dispatcher */
                $dispatcher = $context->get(WebhookDispatcher::class);
                $dispatcher->dispatch($envelope);
            },
            HookPriority::NORMAL,
        );
    }
}

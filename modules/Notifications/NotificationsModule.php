<?php

declare(strict_types=1);

namespace Modules\Notifications;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Event\EventEnvelope;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\User\Entity\User;
use Modules\Notifications\Application\NotifierBridge;
use Modules\Notifications\Application\NotificationsProvider;
use Modules\Notifications\Http\Api\NotificationApiController;
use Modules\Notifications\Http\NotificationController;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotificationsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'notifications';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('notifications.view', 'perm.notifications.view', 'perm.group.notifications');
        $context->permissions()->register('notifications.manage', 'perm.notifications.manage', 'perm.group.notifications');

        $bridge = $context->get(NotifierBridge::class);
        $context->capabilities()->register('notifications', $this->id(), new NotificationsProvider($bridge));

        $context->router()->group('/admin/notifications', function (Router $routes): void {
            $routes->get('', [NotificationController::class, 'index'])
                ->name('admin.notifications.index')
                ->permission('notifications.view');
            $routes->post('/read-all', [NotificationController::class, 'markAllRead'])
                ->name('admin.notifications.read_all')
                ->permission('notifications.view');
            $routes->post('/{id}/read', [NotificationController::class, 'markRead'])
                ->name('admin.notifications.read')
                ->permission('notifications.view');
            $routes->get('/preferences', [NotificationController::class, 'preferences'])
                ->name('admin.notifications.preferences')
                ->permission('notifications.manage');
            $routes->post('/preferences', [NotificationController::class, 'savePreferences'])
                ->name('admin.notifications.preferences.save')
                ->permission('notifications.manage');
        }, ['middleware' => ['auth']]);

        $context->api()->registerKnownScope('notifications.read');
        $context->api()->registerKnownScope('notifications.write');
        $context->api()->module('notifications', function (ApiModule $api): void {
            $api->get('/', [NotificationApiController::class, 'index'])->scope('notifications.read');
            $api->post('/{id}/read', [NotificationApiController::class, 'markRead'])->scope('notifications.write');
            $api->get('/preferences', [NotificationApiController::class, 'preferences'])->scope('notifications.read');
        });

        foreach (['notification.created' => 'notifications.event.created'] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))
                    ->description($desc)
                    ->webhookEnabled(true)
            );
        }
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'notifications',
            label: 'nav.notifications',
            icon: 'bell',
            route: 'admin.notifications.index',
            permission: 'notifications.view',
            order: 15,
            group: 'Hlavní',
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'notifications.index',
            label: 'palette.notifications.index',
            url: '/admin/notifications',
            icon: 'bell',
            permission: 'notifications.view',
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'notifications.preferences',
            label: 'palette.notifications.preferences',
            url: '/admin/notifications/preferences',
            icon: 'settings',
            permission: 'notifications.manage',
            group: 'Účet',
        ));

        $bridge = $context->get(NotifierBridge::class);
        $translator = $context->get(TranslatorInterface::class);
        $em = $context->get(\Doctrine\ORM\EntityManagerInterface::class);

        $context->hooks()->addAction('platform.event.dispatched', function (EventEnvelope $envelope) use ($bridge, $translator): void {
            $type = $envelope->type;
            $interesting = [
                'customer.created', 'deal.created', 'order.created', 'order.confirmed',
                'invoice.issued', 'invoice.paid', 'task.assigned', 'stock.low',
                'document.uploaded', 'project.created',
            ];
            if (!in_array($type, $interesting, true)) {
                return;
            }
            $title = $translator->trans('notifications.inbox.event_title', ['%type%' => $type]);
            $body = $translator->trans('notifications.inbox.event_body', [
                '%type%' => $type,
                '%id%' => (string) ($envelope->resource['id'] ?? ''),
            ]);
            $bridge->notifyAdmins($type, $title, $body, [
                'resource' => $envelope->resource,
                'event_id' => $envelope->id,
            ]);
        }, 50);

        $context->hooks()->addFilter('admin.topbar.actions', function (string $html) use ($bridge, $context): string {
            $auth = $context->get(\Gereq\Auth\Authenticator::class);
            $user = $auth->user();
            if (!$user instanceof User || !$user->can('notifications.view')) {
                return $html;
            }
            $count = $bridge->unreadCount($user->id());
            $url = $context->router()->url('admin.notifications.index');
            $badge = $count > 0 ? '<span class="nav-item__badge">' . $count . '</span>' : '';

            return $html . '<a class="icon-btn" href="' . htmlspecialchars($url) . '" title="Notifications">'
                . '<span aria-hidden="true">🔔</span>' . $badge . '</a>';
        });
    }
}

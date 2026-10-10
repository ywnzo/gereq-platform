<?php

declare(strict_types=1);

namespace Gereq\Notifications;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Admin\Ui\Icons;
use Gereq\Api\ApiModule;
use Gereq\Api\ApiRegistry;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventDefinition;
use Gereq\Event\EventEnvelope;
use Gereq\Event\EventRegistry;
use Gereq\Hook\HookManager;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Notifications\Application\MemberNotifier;
use Gereq\Notifications\Application\NotificationsProvider;
use Gereq\Notifications\Application\NotifierBridge;
use Gereq\Notifications\Application\Widgets\NotificationsShortcutWidget;
use Gereq\Notifications\Application\Widgets\RecentNotificationsWidget;
use Gereq\Notifications\Application\Widgets\UnreadNotificationsWidget;
use Gereq\Notifications\Http\Api\NotificationApiController;
use Gereq\Notifications\Http\NotificationController;
use Gereq\Notifications\Http\Public\PublicMemberNotificationsController;
use Gereq\User\Entity\User;
use Gereq\User\PermissionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Core registration for in-app notifications (formerly modules/Notifications).
 */
final class NotificationsRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly Menu $menu,
        private readonly PermissionRegistry $permissions,
        private readonly CapabilityRegistry $capabilities,
        private readonly ApiRegistry $api,
        private readonly EventRegistry $platformEvents,
        private readonly Dashboard $dashboard,
        private readonly CommandPalette $palette,
        private readonly HookManager $hooks,
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly Authenticator $auth,
        private readonly Icons $icons,
        private readonly NotifierBridge $bridge,
        private readonly MemberNotifier $members,
    ) {
    }

    public function register(): void
    {
        $this->permissions();
        $this->capabilities();
        $this->routes();
        $this->api();
        $this->platformEvents();
        $this->menu();
        $this->dashboard();
        $this->commands();
        $this->hooks();
    }

    private function permissions(): void
    {
        $this->permissions->register('notifications.view', 'perm.notifications.view', 'perm.group.notifications');
        $this->permissions->register('notifications.manage', 'perm.notifications.manage', 'perm.group.notifications');
    }

    private function capabilities(): void
    {
        $this->capabilities->register('notifications', 'core', new NotificationsProvider($this->bridge));
    }

    private function routes(): void
    {
        $this->router->group('/admin/notifications', function (Router $routes): void {
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

        $pub = PublicMemberNotificationsController::class;
        $this->router->get('/api/public/notifications/features', [$pub, 'features'])
            ->name('api.public.notifications.features');
        $this->router->post('/api/public/notifications/stream-token', [$pub, 'streamToken'])
            ->name('api.public.notifications.stream_token');
        $this->router->get('/api/public/notifications/stream', [$pub, 'stream'])
            ->name('api.public.notifications.stream');
        $this->router->get('/api/public/notifications', [$pub, 'index'])
            ->name('api.public.notifications.index');
        $this->router->post('/api/public/notifications/read-all', [$pub, 'markAllRead'])
            ->name('api.public.notifications.read_all');
        $this->router->post('/api/public/notifications/{id}/read', [$pub, 'markRead'])
            ->name('api.public.notifications.read');
        foreach (
            [
            '/api/public/notifications/features',
            '/api/public/notifications',
            '/api/public/notifications/stream-token',
            '/api/public/notifications/stream',
            '/api/public/notifications/read-all',
            '/api/public/notifications/{id}/read',
            ] as $path
        ) {
            $this->router->add($path, ['OPTIONS'], [$pub, 'options']);
        }
    }

    private function api(): void
    {
        $this->api->registerKnownScope('notifications.read');
        $this->api->registerKnownScope('notifications.write');
        $this->api->module('notifications', function (ApiModule $api): void {
            $api->get('/', [NotificationApiController::class, 'index'])->scope('notifications.read');
            $api->post('/{id}/read', [NotificationApiController::class, 'markRead'])->scope('notifications.write');
            $api->get('/preferences', [NotificationApiController::class, 'preferences'])->scope('notifications.read');
        });
    }

    private function platformEvents(): void
    {
        $this->platformEvents->register(
            (new EventDefinition('notification.created', 'core'))
                ->description('notifications.event.created')
                ->webhookEnabled(true)
        );
    }

    private function menu(): void
    {
        $this->menu->add(new MenuItem(
            id: 'notifications',
            label: 'nav.notifications',
            icon: 'bell',
            route: 'admin.notifications.index',
            permission: 'notifications.view',
            order: 55,
            group: 'Hlavní',
        ));
    }

    private function dashboard(): void
    {
        $this->dashboard->addWidget(new NotificationsShortcutWidget(
            $this->twig,
            $this->translator,
            $this->router,
        ));
        foreach ([UnreadNotificationsWidget::class, RecentNotificationsWidget::class] as $widgetClass) {
            $this->dashboard->addWidget(new $widgetClass(
                $this->twig,
                $this->em,
                $this->auth,
                $this->translator,
                $this->router,
            ));
        }
    }

    private function commands(): void
    {
        $this->palette->add(new PaletteCommand(
            id: 'notifications.index',
            label: 'palette.notifications.index',
            url: '/admin/notifications',
            icon: 'bell',
            permission: 'notifications.view',
        ));
        $this->palette->add(new PaletteCommand(
            id: 'notifications.preferences',
            label: 'palette.notifications.preferences',
            url: '/admin/notifications/preferences',
            icon: 'settings',
            permission: 'notifications.manage',
            group: 'Účet',
        ));
    }

    private function hooks(): void
    {
        $bridge = $this->bridge;
        $members = $this->members;
        $translator = $this->translator;

        $this->hooks->addAction(
            'platform.event.dispatched',
            function (EventEnvelope $envelope) use ($bridge, $translator): void {
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
            },
            50,
        );

        $this->hooks->addAction(
            'platform.event.dispatched',
            function (EventEnvelope $envelope) use ($members): void {
                $type = $envelope->type;
                $data = $envelope->data;

                if ($type === 'forum.post.created') {
                    $authorId = (string) ($data['author_id'] ?? '');
                    $recipients = $data['recipient_ids'] ?? [];
                    if (!is_array($recipients)) {
                        $recipients = [];
                    }
                    foreach ($recipients as $rid) {
                        $rid = (string) $rid;
                        if ($rid === '' || $rid === $authorId) {
                            continue;
                        }
                        $members->notify(
                            $rid,
                            $type,
                            'Nová odpověď',
                            'Někdo odpověděl ve vlákně, které sledujete.',
                            'replies',
                            $data,
                        );
                    }

                    return;
                }

                if ($type === 'community.user.followed') {
                    $followingId = (string) ($data['following_id'] ?? '');
                    if ($followingId !== '') {
                        $members->notify(
                            $followingId,
                            $type,
                            'Nový sledující',
                            'Někdo vás začal sledovat.',
                            'mentions',
                            $data,
                        );
                    }

                    return;
                }

                if ($type === 'community.friendship.requested') {
                    $addressee = (string) ($data['addressee_id'] ?? '');
                    if ($addressee !== '') {
                        $members->notify(
                            $addressee,
                            $type,
                            'Žádost o přátelství',
                            'Máte novou žádost o přátelství.',
                            'mentions',
                            $data,
                        );
                    }

                    return;
                }

                if ($type === 'achievement.awarded') {
                    $userId = (string) ($data['user_id'] ?? '');
                    $badge = (string) ($data['badge_name'] ?? 'Odznak');
                    if ($userId !== '') {
                        $members->notify(
                            $userId,
                            $type,
                            'Získaný odznak',
                            'Získali jste odznak: ' . $badge,
                            'badges',
                            $data,
                        );
                    }

                    return;
                }

                if ($type === 'messenger.message.sent') {
                    $recipients = $data['recipient_ids'] ?? [];
                    if (!is_array($recipients)) {
                        return;
                    }
                    foreach ($recipients as $rid) {
                        $rid = (string) $rid;
                        if ($rid === '') {
                            continue;
                        }
                        $members->notify(
                            $rid,
                            $type,
                            'Nová zpráva',
                            'Máte novou soukromou zprávu.',
                            'replies',
                            $data,
                        );
                    }
                }
            },
            45,
        );

        $this->hooks->addFilter('admin.topbar.actions', function (string $html) use ($bridge): string {
            $user = $this->auth->user();
            if (!$user instanceof User || !$user->can('notifications.view')) {
                return $html;
            }
            $count = $bridge->unreadCount($user->id());
            $url = $this->router->url('admin.notifications.index');
            $badge = $count > 0 ? '<span class="nav-item__badge">' . $count . '</span>' : '';
            $icon = $this->icons->svg('bell');

            return $html . '<a class="icon-btn" href="' . htmlspecialchars($url)
                . '" title="Notifications">' . $icon . $badge . '</a>';
        });
    }
}

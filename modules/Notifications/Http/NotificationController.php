<?php

declare(strict_types=1);

namespace Modules\Notifications\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Notification\DatabaseNotification;
use Gereq\User\Entity\User;
use Modules\Notifications\Application\NotifierBridge;
use Modules\Notifications\Domain\NotificationPreference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class NotificationController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly NotifierBridge $bridge,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $user = $this->requireUser();
        $items = $this->bridge->inbox($user->id(), 100);

        return $this->page('@notifications/index.html.twig', [
            'pageTitle' => $this->translator->trans('notifications.title'),
            'items' => $items,
            'unread' => $this->bridge->unreadCount($user->id()),
        ]);
    }

    public function markRead(string $id): Response
    {
        $user = $this->requireUser();
        $item = $this->em->find(DatabaseNotification::class, $id);
        if (!$item instanceof DatabaseNotification || $item->userId() !== $user->id()) {
            $this->abort(404, $this->translator->trans('notifications.error.not_found'));
        }
        $item->markRead();
        $this->em->flush();
        $this->auditor->log('notification.read', 'notification', $id);
        $this->flash('success', $this->translator->trans('notifications.flash.marked_read'));

        return $this->redirect('admin.notifications.index');
    }

    public function markAllRead(): Response
    {
        $user = $this->requireUser();
        $items = $this->em->getRepository(DatabaseNotification::class)->findBy([
            'userId' => $user->id(),
            'readAt' => null,
        ]);
        foreach ($items as $item) {
            $item->markRead();
        }
        $this->em->flush();
        $this->flash('success', $this->translator->trans('notifications.flash.all_read'));

        return $this->redirect('admin.notifications.index');
    }

    public function preferences(): Response
    {
        $user = $this->requireUser();
        $prefs = $this->em->getRepository(NotificationPreference::class)->findBy(['userId' => $user->id()]);
        $map = [];
        foreach ($prefs as $pref) {
            $map[$pref->channel() . ':' . $pref->eventType()] = $pref->enabled();
        }

        return $this->page('@notifications/preferences.html.twig', [
            'pageTitle' => $this->translator->trans('notifications.preferences.title'),
            'eventTypes' => ['customer.created', 'deal.created', 'order.created', 'invoice.issued', 'task.assigned', '*'],
            'channels' => ['in_app', 'email'],
            'map' => $map,
        ]);
    }

    public function savePreferences(Request $request): Response
    {
        $user = $this->requireUser();
        $posted = $request->request->all('pref') ?: [];
        $eventTypes = ['customer.created', 'deal.created', 'order.created', 'invoice.issued', 'task.assigned', '*'];
        $channels = ['in_app', 'email'];

        foreach ($channels as $channel) {
            foreach ($eventTypes as $type) {
                $key = $channel . '__' . str_replace('.', '_', $type);
                $enabled = isset($posted[$key]);
                $pref = $this->em->getRepository(NotificationPreference::class)->findOneBy([
                    'userId' => $user->id(),
                    'channel' => $channel,
                    'eventType' => $type,
                ]);
                if ($pref instanceof NotificationPreference) {
                    $pref->setEnabled($enabled);
                } else {
                    $this->em->persist(new NotificationPreference($user->id(), $channel, $type, $enabled));
                }
            }
        }
        $this->em->flush();
        $this->auditor->log('notification.preferences_updated', 'user', $user->id());
        $this->flash('success', $this->translator->trans('notifications.flash.preferences_saved'));

        return $this->redirect('admin.notifications.preferences');
    }

    private function requireUser(): User
    {
        $user = $this->auth->user();
        if (!$user instanceof User) {
            $this->abort(401);
        }

        return $user;
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
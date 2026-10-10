<?php

declare(strict_types=1);

namespace Gereq\Notifications\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Gereq\Notifications\Channel\DatabaseNotification;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Latest unread notifications of the current user. */
final class RecentNotificationsWidget extends TwigDashboardWidget
{
    private const LIMIT = 8;

    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'notifications.recent';
    }

    public function title(): string
    {
        return $this->translator->trans('notifications.widget.recent.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'notifications.view';
    }

    public function order(): int
    {
        return 38;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        $qb = $this->em->getRepository(DatabaseNotification::class)->createQueryBuilder('n')
            ->where('n.readAt IS NULL')
            ->andWhere('n.actorType = :actor')
            ->setParameter('actor', DatabaseNotification::ACTOR_USER)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults(self::LIMIT);

        $userId = $this->auth->user()?->id();
        if ($userId !== null) {
            $qb->andWhere('n.userId = :uid')->setParameter('uid', $userId);
        }

        /** @var list<DatabaseNotification> $notifications */
        $notifications = $qb->getQuery()->getResult();
        $href = $this->router->url('admin.notifications.index');

        $items = [];
        foreach ($notifications as $notification) {
            $data = $notification->data();
            $meta = $data['meta'] ?? [];
            $eventType = is_array($meta) ? (string) ($meta['event_type'] ?? '') : '';
            $title = trim((string) ($data['title'] ?? ''));
            $body = trim((string) ($data['body'] ?? ''));

            $items[] = [
                'title' => $title !== '' ? $title : $notification->type(),
                'meta' => $body !== '' ? $body : $eventType,
                'icon' => 'bell',
                'href' => $href,
                'aside' => $notification->createdAt()->format('j. n.'),
                'aside_hint' => $notification->createdAt()->format('H:i'),
                'badge' => $eventType !== ''
                    ? ['label' => $eventType, 'tone' => 'info']
                    : ['label' => $this->translator->trans('notifications.status.unread'), 'tone' => 'signal'],
            ];
        }

        return [
            'title' => $this->translator->trans('notifications.widget.recent.title'),
            'subtitle' => $this->translator->trans('notifications.widget.recent.subtitle'),
            'href' => $href,
            'link_label' => $this->translator->trans('nav.notifications'),
            'items' => $items,
            'empty_message' => $this->translator->trans('notifications.widget.recent.empty'),
        ];
    }
}

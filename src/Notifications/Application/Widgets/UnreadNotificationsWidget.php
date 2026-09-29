<?php

declare(strict_types=1);

namespace Gereq\Notifications\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Gereq\Notification\DatabaseNotification;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Unread notifications of the current user + how many arrived in the last 24 hours. */
final class UnreadNotificationsWidget extends TwigDashboardWidget
{
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
        return 'notifications.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('notifications.widget.stats.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'notifications.view';
    }

    public function order(): int
    {
        return 60;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $userId = $this->auth->user()?->id();

        $unread = $this->unreadQuery($userId)
            ->getQuery()
            ->getSingleScalarResult();

        $recent = $this->unreadQuery($userId)
            ->andWhere('n.createdAt >= :since')
            ->setParameter('since', new \DateTimeImmutable('-24 hours'))
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'label' => $this->translator->trans('notifications.widget.stats.title'),
            'value' => (string) (int) $unread,
            'hint' => $this->translator->trans('notifications.widget.stats.hint', ['%count%' => (string) (int) $recent]),
            'href' => $this->router->url('admin.notifications.index'),
        ];
    }

    private function unreadQuery(?string $userId): QueryBuilder
    {
        $qb = $this->em->getRepository(DatabaseNotification::class)->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.readAt IS NULL');

        if ($userId !== null) {
            $qb->andWhere('n.userId = :uid')->setParameter('uid', $userId);
        }

        return $qb;
    }
}

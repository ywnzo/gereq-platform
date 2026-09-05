<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Gereq\User\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class UsersStatsWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'core.users';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.users');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'users.view';
    }

    public function order(): int
    {
        return 20;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $count = (int) $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'label' => $this->translator->trans('nav.users'),
            'value' => (string) $count,
            'hint' => $this->translator->trans('admin.dashboard.users_hint'),
            'href' => $this->router->url('admin.users.index'),
        ];
    }
}

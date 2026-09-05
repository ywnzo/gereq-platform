<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Modules\Orders\Domain\Order;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class OrderRevenueWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'orders.revenue';
    }

    public function title(): string
    {
        return $this->translator->trans('admin.dashboard.revenue');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'orders.view';
    }

    public function order(): int
    {
        return 35;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $since = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
        $revenue = (float) $this->em->getRepository(Order::class)->createQueryBuilder('o')
            ->select('COALESCE(SUM(o.total), 0)')
            ->where('o.createdAt >= :since')
            ->andWhere('o.status NOT IN (:cancelled)')
            ->setParameter('since', $since)
            ->setParameter('cancelled', ['cancelled'])
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'label' => $this->translator->trans('admin.dashboard.revenue'),
            'value' => number_format($revenue, 0, ',', ' '),
            'hint' => 'CZK',
        ];
    }
}

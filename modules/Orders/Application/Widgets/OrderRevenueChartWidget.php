<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\ChartSeries;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Orders\Domain\Order;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class OrderRevenueChartWidget extends TwigDashboardWidget
{
    private const DAYS = 30;

    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
        private readonly CurrencyBuilder $money,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'orders.revenue.chart';
    }

    public function title(): string
    {
        return $this->translator->trans('admin.dashboard.chart.revenue');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'orders.view';
    }

    public function order(): int
    {
        return 15;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/chart_panel.html.twig';
    }

    protected function data(): array
    {
        $days = ChartSeries::dayLabels(self::DAYS);
        $since = new \DateTimeImmutable($days[0] . ' 00:00:00');
        /** @var list<Order> $orders */
        $orders = $this->em->getRepository(Order::class)->createQueryBuilder('o')
            ->where('o.createdAt >= :since')
            ->andWhere('o.status NOT IN (:cancelled)')
            ->setParameter('since', $since)
            ->setParameter('cancelled', ['cancelled'])
            ->getQuery()
            ->getResult();

        $points = [];
        $countPoints = [];
        foreach ($orders as $order) {
            $points[] = [$order->createdAt(), $order->total()];
            $countPoints[] = $order->createdAt();
        }

        $revenue = ChartSeries::sumsPerDay($days, $points);
        $counts = ChartSeries::countsPerDay($days, $countPoints);
        $sum = array_sum($revenue);
        $avg = $sum > 0 ? $sum / max(1, count(array_filter($revenue, static fn (float $v): bool => $v > 0))) : 0.0;

        return [
            'title' => $this->translator->trans('admin.dashboard.chart.revenue'),
            'subtitle' => $this->translator->trans('admin.dashboard.chart.revenue_sub', ['%days%' => (string) self::DAYS]),
            'href' => $this->router->url('admin.orders.index'),
            'link_label' => $this->translator->trans('nav.orders'),
            'wide' => true,
            'empty' => $sum <= 0 && array_sum($counts) === 0,
            'empty_message' => $this->translator->trans('empty.generic'),
            'stats' => [
                [
                    'label' => $this->translator->trans('admin.dashboard.chart.revenue_sum'),
                    'value' => $this->money->format($sum),
                ],
                [
                    'label' => $this->translator->trans('admin.dashboard.chart.orders_count'),
                    'value' => (string) array_sum($counts),
                ],
                [
                    'label' => $this->translator->trans('admin.dashboard.chart.avg_day'),
                    'value' => $this->money->format($avg),
                ],
            ],
            'config' => [
                'type' => 'line',
                'labels' => ChartSeries::shortLabels($days),
                'datasets' => [
                    [
                        'label' => $this->translator->trans('admin.dashboard.chart.revenue'),
                        'data' => $revenue,
                        'kind' => 'line',
                        'fill' => true,
                    ],
                ],
            ],
        ];
    }
}

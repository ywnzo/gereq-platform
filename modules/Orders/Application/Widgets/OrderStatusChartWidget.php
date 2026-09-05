<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Orders\Domain\Order;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class OrderStatusChartWidget extends TwigDashboardWidget
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
        return 'orders.status.chart';
    }

    public function title(): string
    {
        return $this->translator->trans('admin.dashboard.chart.order_status');
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
        return 16;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/chart_panel.html.twig';
    }

    protected function data(): array
    {
        $rows = $this->em->getRepository(Order::class)->createQueryBuilder('o')
            ->select('o.status AS status, COUNT(o.id) AS cnt')
            ->groupBy('o.status')
            ->getQuery()
            ->getArrayResult();

        $labels = [];
        $values = [];
        $total = 0;
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['cnt'] ?? 0);
            if ($status === '' || $count < 1) {
                continue;
            }
            $labels[] = $this->translator->trans('orders.status.' . $status);
            $values[] = $count;
            $total += $count;
        }

        return [
            'title' => $this->translator->trans('admin.dashboard.chart.order_status'),
            'subtitle' => $this->translator->trans('admin.dashboard.chart.order_status_sub'),
            'href' => $this->router->url('admin.orders.index'),
            'wide' => false,
            'empty' => $total === 0,
            'empty_message' => $this->translator->trans('empty.generic'),
            'stats' => [
                ['label' => $this->translator->trans('admin.dashboard.chart.orders_count'), 'value' => (string) $total],
            ],
            'config' => [
                'type' => 'doughnut',
                'labels' => $labels,
                'datasets' => [[
                    'label' => $this->translator->trans('nav.orders'),
                    'data' => $values,
                    'kind' => 'doughnut',
                ]],
            ],
        ];
    }
}

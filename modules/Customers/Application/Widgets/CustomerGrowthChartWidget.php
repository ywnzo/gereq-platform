<?php

declare(strict_types=1);

namespace Modules\Customers\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\ChartSeries;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Customers\Domain\Customer;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CustomerGrowthChartWidget extends TwigDashboardWidget
{
    private const DAYS = 30;

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
        return 'customers.growth.chart';
    }

    public function title(): string
    {
        return $this->translator->trans('admin.dashboard.chart.customers');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'customers.view';
    }

    public function order(): int
    {
        return 18;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/chart_panel.html.twig';
    }

    protected function data(): array
    {
        $days = ChartSeries::dayLabels(self::DAYS);
        $since = new \DateTimeImmutable($days[0] . ' 00:00:00');
        /** @var list<Customer> $customers */
        $customers = $this->em->getRepository(Customer::class)->createQueryBuilder('c')
            ->where('c.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        $timestamps = array_map(static fn (Customer $c): \DateTimeImmutable => $c->createdAt(), $customers);
        $values = ChartSeries::countsPerDay($days, $timestamps);
        $new = array_sum($values);

        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Customer::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'title' => $this->translator->trans('admin.dashboard.chart.customers'),
            'subtitle' => $this->translator->trans('admin.dashboard.chart.customers_sub', ['%days%' => (string) self::DAYS]),
            'href' => $this->router->url('admin.customers.index'),
            'link_label' => $this->translator->trans('nav.customers'),
            'wide' => true,
            'empty' => $new === 0,
            'empty_message' => $this->translator->trans('empty.generic'),
            'stats' => [
                ['label' => $this->translator->trans('admin.dashboard.chart.new'), 'value' => (string) $new],
                ['label' => $this->translator->trans('admin.dashboard.chart.total'), 'value' => (string) $total],
            ],
            'config' => [
                'type' => 'line',
                'labels' => ChartSeries::shortLabels($days),
                'datasets' => [[
                    'label' => $this->translator->trans('admin.dashboard.chart.new_customers'),
                    'data' => $values,
                    'kind' => 'line',
                    'fill' => true,
                    'color' => '#34d399',
                ]],
            ],
        ];
    }
}
